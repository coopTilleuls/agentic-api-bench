// complexity-tool: static measurements for eval/complexity.sh.
//
//	complexity-tool config [-base OLD] FILE   keys and leaf values of a JSON/YAML file
//	complexity-tool sql [-base OLD] [-v] FILE SQL statements embedded in a file
//
// With -base, only what FILE has beyond OLD is counted (multiset difference).
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"gopkg.in/yaml.v3"
)

func main() {
	if len(os.Args) < 3 {
		fmt.Fprintln(os.Stderr, "usage: complexity-tool config|sql [-base OLD] [-v] FILE")
		os.Exit(2)
	}
	cmd := os.Args[1]
	fs := flag.NewFlagSet(cmd, flag.ExitOnError)
	base := fs.String("base", "", "pristine version of the file; count only additions")
	verbose := fs.Bool("v", false, "list every statement")
	fs.Parse(os.Args[2:])
	if fs.NArg() != 1 {
		fmt.Fprintln(os.Stderr, "one FILE expected")
		os.Exit(2)
	}
	file := fs.Arg(0)
	var out any
	var err error
	switch cmd {
	case "config":
		out, err = runConfig(file, *base)
	case "sql":
		out, err = runSQL(file, *base, *verbose)
	default:
		err = fmt.Errorf("unknown command %q", cmd)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", " ")
	enc.Encode(out)
}

func readFile(p string) (string, error) {
	b, err := os.ReadFile(p)
	return string(b), err
}

func ext(p string) string { return strings.ToLower(filepath.Ext(p)) }

// ---- config: keys and leaves ----

type configResult struct {
	Keys   int `json:"keys"`
	Leaves int `json:"leaves"`
}

func decodeDocs(path, src string) ([]any, error) {
	var docs []any
	if ext(path) == ".json" {
		var v any
		if err := json.Unmarshal([]byte(src), &v); err != nil {
			if err := yaml.Unmarshal([]byte(src), &v); err != nil {
				return nil, err
			}
		}
		return []any{v}, nil
	}
	dec := yaml.NewDecoder(strings.NewReader(src))
	for {
		var v any
		if err := dec.Decode(&v); err != nil {
			if err.Error() == "EOF" {
				break
			}
			return nil, err
		}
		docs = append(docs, v)
	}
	return docs, nil
}

// walkConfig emits "k\x00path" for every mapping key and "l\x00path=value" for
// every scalar (an empty map or list counts as one leaf). List positions are
// not part of the path so that insertions do not shift everything.
func walkConfig(v any, path string, keys, leaves *[]string) {
	switch t := v.(type) {
	case map[string]any:
		if len(t) == 0 {
			*leaves = append(*leaves, path+"={}")
		}
		for k, c := range t {
			p := path + "/" + k
			*keys = append(*keys, p)
			walkConfig(c, p, keys, leaves)
		}
	case map[any]any:
		if len(t) == 0 {
			*leaves = append(*leaves, path+"={}")
		}
		for k, c := range t {
			p := path + "/" + fmt.Sprint(k)
			*keys = append(*keys, p)
			walkConfig(c, p, keys, leaves)
		}
	case []any:
		if len(t) == 0 {
			*leaves = append(*leaves, path+"=[]")
		}
		for _, c := range t {
			walkConfig(c, path+"[]", keys, leaves)
		}
	default:
		*leaves = append(*leaves, fmt.Sprintf("%s=%v", path, t))
	}
}

func configEntries(path, src string) (keys, leaves []string, err error) {
	docs, err := decodeDocs(path, src)
	if err != nil {
		return nil, nil, err
	}
	for _, d := range docs {
		walkConfig(d, "", &keys, &leaves)
	}
	return keys, leaves, nil
}

func multisetExtra(cur, old []string) int {
	have := map[string]int{}
	for _, o := range old {
		have[o]++
	}
	n := 0
	for _, c := range cur {
		if have[c] > 0 {
			have[c]--
		} else {
			n++
		}
	}
	return n
}

func runConfig(file, base string) (any, error) {
	src, err := readFile(file)
	if err != nil {
		return nil, err
	}
	k, l, err := configEntries(file, src)
	if err != nil {
		return nil, err
	}
	if base == "" {
		return configResult{len(k), len(l)}, nil
	}
	osrc, err := readFile(base)
	if err != nil {
		return nil, err
	}
	ok, ol, err := configEntries(base, osrc)
	if err != nil {
		return nil, err
	}
	return configResult{multisetExtra(k, ok), multisetExtra(l, ol)}, nil
}
