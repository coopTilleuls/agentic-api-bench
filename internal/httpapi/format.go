package httpapi

import (
	"encoding/csv"
	"fmt"
	"io"
	"mime"
	"net/http"
	"net/url"
	"slices"
	"strconv"
	"strings"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

// Collection is what a Format renders for a collection request. Each yields
// the items; for unpaginated exports it streams them from the database.
type Collection struct {
	Def          *resource.Definition
	URL          *url.URL
	Total        int64
	Page         int
	ItemsPerPage int
	Paginated    bool
	// Envelope wraps JSON collections: output key per value (items, total,
	// page, itemsPerPage, pages); empty keeps a bare array.
	Envelope map[string]string
	Each     func(yield func(resource.Item) error) error
}

// Format serializes items. New output formats (xlsx, pdf) implement it and
// are added to Formats.
type Format interface {
	Name() string
	MediaType() string
	WriteItem(w io.Writer, d *resource.Definition, item resource.Item) error
	WriteCollection(w io.Writer, c Collection) error
}

var Formats = []Format{jsonldFormat{}, jsonFormat{}, csvFormat{}}

func formatByName(name string) Format {
	for _, f := range Formats {
		if f.Name() == name {
			return f
		}
	}
	return nil
}

// hasItemIRIs is false when rows have no item endpoint to point to
// (operations without a GET /{id} one).
func hasItemIRIs(d *resource.Definition) bool {
	return itemPrefix(d) != "" && d.Identifier().Name != ""
}

func itemPrefix(d *resource.Definition) string {
	if len(d.Ops) == 0 {
		return d.Path
	}
	for _, op := range d.Ops {
		if op.IsItem() && op.Suffix() == "" && op.Reads() {
			return op.Prefix()
		}
	}
	return ""
}

func itemPath(d *resource.Definition, id any) string {
	prefix := itemPrefix(d)
	if prefix == "" {
		return ""
	}
	return prefix + "/" + url.PathEscape(fmt.Sprint(id))
}

type jsonldFormat struct{}

func (jsonldFormat) Name() string      { return "jsonld" }
func (jsonldFormat) MediaType() string { return FormatJSONLD }
func (jsonldFormat) WriteItem(w io.Writer, d *resource.Definition, item resource.Item) error {
	return writeJSON(w, itemDoc(d, item, true))
}
func (jsonldFormat) WriteCollection(w io.Writer, c Collection) error {
	var items []resource.Item
	if err := c.Each(func(it resource.Item) error { items = append(items, it); return nil }); err != nil {
		return err
	}
	return writeJSON(w, collectionDoc(c.Def, items, c.Total, c.Page, c.ItemsPerPage, c.URL))
}

type jsonFormat struct{}

func (jsonFormat) Name() string      { return "json" }
func (jsonFormat) MediaType() string { return FormatJSON }
func (jsonFormat) WriteItem(w io.Writer, d *resource.Definition, item resource.Item) error {
	return writeJSON(w, plainItem(d, item))
}
func (jsonFormat) WriteCollection(w io.Writer, c Collection) error {
	out := []Obj{}
	if err := c.Each(func(it resource.Item) error { out = append(out, plainItem(c.Def, it)); return nil }); err != nil {
		return err
	}
	if len(c.Envelope) == 0 {
		return writeJSON(w, out)
	}
	pages := int64(0)
	if c.ItemsPerPage > 0 {
		pages = (c.Total + int64(c.ItemsPerPage) - 1) / int64(c.ItemsPerPage)
	}
	values := map[string]any{"items": out, "total": c.Total, "page": c.Page, "itemsPerPage": c.ItemsPerPage, "pages": pages}
	doc := Obj{}
	for _, k := range resource.EnvelopeKeys {
		if name, ok := c.Envelope[k]; ok {
			doc = append(doc, kv{name, values[k]})
		}
	}
	return writeJSON(w, doc)
}

type csvFormat struct{}

func (csvFormat) Name() string      { return "csv" }
func (csvFormat) MediaType() string { return FormatCSV }
func (csvFormat) WriteItem(w io.Writer, d *resource.Definition, item resource.Item) error {
	return csvFormat{}.WriteCollection(w, Collection{Def: d, Each: func(yield func(resource.Item) error) error { return yield(item) }})
}

// WriteCollection flushes every 100 rows so large exports stream instead of
// being buffered.
func (csvFormat) WriteCollection(w io.Writer, c Collection) error {
	cw := csv.NewWriter(w)
	props := c.Def.Output()
	header := make([]string, len(props))
	for i, p := range props {
		header[i] = p.Name
	}
	if err := cw.Write(header); err != nil {
		return err
	}
	flusher, _ := w.(http.Flusher)
	n := 0
	err := c.Each(func(it resource.Item) error {
		rec := make([]string, len(props))
		for i, p := range props {
			rec[i] = csvValue(it[p.Name])
		}
		if err := cw.Write(rec); err != nil {
			return err
		}
		n++
		if n%100 == 0 {
			cw.Flush()
			if flusher != nil {
				flusher.Flush()
			}
		}
		return nil
	})
	if err != nil {
		return err
	}
	cw.Flush()
	return cw.Error()
}

func csvValue(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case float64:
		return strconv.FormatFloat(x, 'f', -1, 64)
	case resource.Object, []any, map[string]any:
		b, _ := Marshal(x)
		return string(b)
	}
	return fmt.Sprint(v)
}

func writeJSON(w io.Writer, v any) error {
	b, err := Marshal(v)
	if err != nil {
		return err
	}
	_, err = w.Write(append(b, '\n'))
	return err
}

func plainItem(d *resource.Definition, item resource.Item) Obj {
	props := d.Output()
	o := make(Obj, 0, len(props))
	for _, p := range props {
		o = append(o, kv{p.Name, item[p.Name]})
	}
	return o
}

var suffixes = map[string]string{".jsonld": "jsonld", ".json": "json", ".csv": "csv"}

// splitSuffix strips a format suffix such as /books.csv or /books/1.json.
func splitSuffix(path string) (string, string) {
	for suffix, name := range suffixes {
		if strings.HasSuffix(path, suffix) {
			return strings.TrimSuffix(path, suffix), name
		}
	}
	return path, ""
}

func notAcceptable(d *resource.Definition, allowed []string, asked string) *apierr.Error {
	media := make([]string, len(allowed))
	for i, n := range allowed {
		media[i] = formatByName(n).MediaType()
	}
	return &apierr.Error{Code: apierr.NotAcceptable, Resource: d.Name, Pointer: "/formats", Message: fmt.Sprintf("%s cannot be served as %s", d.Name, asked),
		Hint: fmt.Sprintf("Allowed formats: %s (Accept: %s, or ?format=<name>, or a .<name> path suffix). Add the format to \"formats\" in the definition to allow it.", strings.Join(allowed, ", "), strings.Join(media, ", "))}
}

// formats is the allowed list of d: its own "formats", else the site's,
// else every format.
func (s *Server) formats(d *resource.Definition) []string {
	if len(d.Formats) > 0 {
		return d.Formats
	}
	if len(s.Output.Formats) > 0 {
		return s.Output.Formats
	}
	return resource.AllFormats
}

// chooseFormat applies, in order: the operation's pinned format, path
// suffix, ?format=, Accept. Accept ranges are tried in the order sent; */*
// picks the first allowed format.
func (s *Server) chooseFormat(r *http.Request, d *resource.Definition, suffix string) (Format, *apierr.Error) {
	if d.Current != nil && d.Current.Format != "" {
		return formatByName(d.Current.Format), nil
	}
	allowed := s.formats(d)
	explicit := suffix
	if explicit == "" {
		explicit = r.URL.Query().Get("format")
	}
	if explicit != "" {
		f := formatByName(explicit)
		if f == nil || !slices.Contains(allowed, explicit) {
			return nil, notAcceptable(d, allowed, strconv.Quote(explicit))
		}
		return f, nil
	}
	accept := strings.TrimSpace(r.Header.Get("Accept"))
	if accept == "" {
		return formatByName(allowed[0]), nil
	}
	wildcard := false
	for _, part := range strings.Split(accept, ",") {
		mt, params, err := mime.ParseMediaType(strings.TrimSpace(part))
		if err != nil || params["q"] == "0" {
			continue
		}
		if mt == "*/*" || mt == "application/*" || mt == "text/*" {
			wildcard = true
			continue
		}
		for _, name := range allowed {
			if formatByName(name).MediaType() == mt {
				return formatByName(name), nil
			}
		}
	}
	if wildcard {
		return formatByName(allowed[0]), nil
	}
	return nil, notAcceptable(d, allowed, strconv.Quote(accept))
}
