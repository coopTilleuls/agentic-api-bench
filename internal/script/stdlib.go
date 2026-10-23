package script

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/sha512"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"hash"
	"math"
	"mime"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

// Response is a raw HTTP response returned by an operation script with
// ctx.response: it bypasses the serialization of the resource.
type Response struct {
	Status  int
	Headers map[string]string
	Body    string
}

const maxRandomBytes = 1024

var hashAlgos = map[string]func() hash.Hash{
	"sha256": sha256.New,
	"sha512": sha512.New,
	"sha1":   sha1.New,
}

func cryptoErr(format string, args ...any) *apierr.Error {
	return &apierr.Error{Code: apierr.InvalidCryptoCall, Message: fmt.Sprintf(format, args...)}
}

func cryptoHint(e *apierr.Error, hint string) *apierr.Error {
	e.Hint = hint
	return e
}

func hashFor(what, algo string) (func() hash.Hash, *apierr.Error) {
	if h, ok := hashAlgos[algo]; ok {
		return h, nil
	}
	return nil, cryptoHint(cryptoErr("%s: unknown algorithm %q", what, algo), "The algorithm is one of sha256, sha512, sha1 (lower case).")
}

func hashHex(algo, data string) string {
	h := hashAlgos[algo]()
	h.Write([]byte(data))
	return hex.EncodeToString(h.Sum(nil))
}

func hmacHex(algo, key, data string) (string, *apierr.Error) {
	h, err := hashFor("ctx.crypto.hmac", algo)
	if err != nil {
		return "", err
	}
	m := hmac.New(h, []byte(key))
	m.Write([]byte(data))
	return hex.EncodeToString(m.Sum(nil)), nil
}

func constantTimeEqual(a, b string) bool {
	return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}

func randomHex(n int64) (string, *apierr.Error) {
	if n < 1 || n > maxRandomBytes {
		return "", cryptoHint(cryptoErr("ctx.crypto.randomHex(%d): the number of bytes must be between 1 and %d", n, maxRandomBytes), "randomHex(16) returns 32 hex characters (128 bits), enough for a token.")
	}
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b), nil
}

func newUUID() string {
	return uuid.NewString()
}

func hexDecode(s string) (string, *apierr.Error) {
	b, err := hex.DecodeString(s)
	if err != nil {
		return "", cryptoHint(cryptoErr("ctx.crypto.hexDecode: %q is not valid hex (%v)", truncate(s, 40), err), "Hex holds an even number of characters 0-9 a-f; no prefix, no spaces.")
	}
	return utf8Text("ctx.crypto.hexDecode", b)
}

func base64Decode(s string) (string, *apierr.Error) {
	for _, enc := range []*base64.Encoding{base64.StdEncoding, base64.RawStdEncoding, base64.URLEncoding, base64.RawURLEncoding} {
		if b, err := enc.DecodeString(s); err == nil {
			return utf8Text("ctx.crypto.base64Decode", b)
		}
	}
	return "", cryptoHint(cryptoErr("ctx.crypto.base64Decode: %q is not valid base64", truncate(s, 40)), "Use the standard (+/) or URL-safe (-_) alphabet; padding is optional.")
}

func utf8Text(what string, b []byte) (string, *apierr.Error) {
	if !utf8.Valid(b) {
		return "", cryptoHint(cryptoErr("%s: the decoded bytes are not UTF-8 text", what), "Scripts only handle UTF-8 strings: decode text, and compare binary values in their encoded form (hex or base64).")
	}
	return string(b), nil
}

func base64Encode(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
func hexEncode(s string) string    { return hex.EncodeToString([]byte(s)) }

const isoMillis = "2006-01-02T15:04:05.000Z"

func timeNow() string { return time.Now().UTC().Format(isoMillis) }

func timeUnix() float64 {
	n := time.Now()
	return float64(n.Unix()) + float64(n.Nanosecond())/1e9
}

var timeLayouts = []string{time.RFC3339Nano, "2006-01-02T15:04:05.999999999", "2006-01-02"}

func timeParse(s string) (float64, *apierr.Error) {
	for _, layout := range timeLayouts {
		if t, err := time.Parse(layout, s); err == nil {
			return float64(t.Unix()) + float64(t.Nanosecond())/1e9, nil
		}
	}
	return 0, &apierr.Error{Code: apierr.InvalidTimeValue, Message: fmt.Sprintf("ctx.time.parse: %q is not an ISO 8601 date or datetime", truncate(s, 40)), Hint: `Pass "2024-01-31T12:00:00Z", "2024-01-31T12:00:00.500+02:00", "2024-01-31T12:00:00" (read as UTC) or "2024-01-31".`}
}

func timeFormat(seconds float64) (string, *apierr.Error) {
	if math.IsNaN(seconds) || math.IsInf(seconds, 0) || math.Abs(seconds) > 253402300799 {
		return "", &apierr.Error{Code: apierr.InvalidTimeValue, Message: fmt.Sprintf("ctx.time.format: %v is not a usable number of epoch seconds", seconds), Hint: "Pass seconds since 1970-01-01T00:00:00Z, e.g. ctx.time.unix() or ctx.time.parse(iso)."}
	}
	return time.UnixMilli(int64(math.Round(seconds * 1000))).UTC().Format(isoMillis), nil
}

// accepts reports whether an Accept header admits a media type: the most
// specific range naming it decides, and q=0 refuses. No header accepts all.
func accepts(header, typ string) bool {
	if strings.TrimSpace(header) == "" {
		return true
	}
	typ = strings.ToLower(strings.TrimSpace(typ))
	family, _, _ := strings.Cut(typ, "/")
	best, bestRank := false, 0
	for _, part := range strings.Split(header, ",") {
		mt, params, err := mime.ParseMediaType(strings.TrimSpace(part))
		if err != nil {
			continue
		}
		rank := 0
		switch {
		case mt == typ:
			rank = 3
		case mt == family+"/*":
			rank = 2
		case mt == "*/*":
			rank = 1
		}
		if rank == 0 || rank < bestRank {
			continue
		}
		q := 1.0
		if v, ok := params["q"]; ok {
			if _, err := fmt.Sscanf(v, "%g", &q); err != nil {
				q = 1
			}
		}
		best, bestRank = q > 0, rank
	}
	return best
}

func responseErr(format string, args ...any) *apierr.Error {
	return &apierr.Error{Code: apierr.InvalidResponse, Message: fmt.Sprintf("ctx.response: "+format, args...), Hint: `Call ctx.response({status: 200, headers: {"Content-Type": "text/html; charset=utf-8"}, body: "<h1>Hi</h1>"}): status is an integer 100-599 (default 200), headers map names to strings, body is a string.`}
}

func notAllowedResponse() *apierr.Error {
	return &apierr.Error{Code: apierr.ResponseNotAllowed, Message: "ctx.response is only available in the query of a GET operation (read phase) and in the command of a POST, PUT, PATCH or DELETE operation", Hint: `Declare the endpoint in "operations" of a definition (e.g. {"method": "GET", "path": "/welcome", "query": "welcome.query.ts"}). Resources, parameter handlers and the lookup phase always serialize their own representation.`}
}

func validHeaderName(s string) bool {
	if s == "" {
		return false
	}
	for _, r := range s {
		if r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || strings.ContainsRune("!#$%&'*+-.^_`|~", r) {
			continue
		}
		return false
	}
	return true
}

// buildResponse validates the argument of ctx.response, already exported to
// plain Go data (status integer, headers map, body string).
func buildResponse(fields map[string]any) (*Response, *apierr.Error) {
	r := &Response{Status: 200, Headers: map[string]string{}}
	for k, v := range fields {
		switch k {
		case "status":
			n, ok := integer(v)
			if !ok || n < 100 || n > 599 {
				return nil, responseErr("status must be an integer between 100 and 599, got %v", v)
			}
			r.Status = int(n)
		case "headers":
			if v == nil {
				continue
			}
			m, ok := v.(map[string]any)
			if !ok {
				return nil, responseErr("headers must be an object of strings, got %v", v)
			}
			for name, hv := range m {
				s, ok := hv.(string)
				if !ok || !validHeaderName(name) || strings.ContainsAny(s, "\r\n") {
					return nil, responseErr("header %q is not valid: names are HTTP tokens and values single-line strings", name)
				}
				r.Headers[name] = s
			}
		case "body":
			if v == nil {
				continue
			}
			s, ok := v.(string)
			if !ok {
				return nil, responseErr("body must be a string (serialize JSON yourself with JSON.stringify or ctx.json.encode), got %T", v)
			}
			r.Body = s
		default:
			return nil, responseErr("unknown key %q: the keys are status, headers and body", k)
		}
	}
	return r, nil
}

func integer(v any) (int64, bool) {
	switch x := v.(type) {
	case int64:
		return x, true
	case int:
		return int64(x), true
	case float64:
		return int64(x), x == math.Trunc(x)
	}
	return 0, false
}

func requestJSON(body string) (any, *apierr.Error) {
	var v any
	if err := json.Unmarshal([]byte(body), &v); err != nil {
		return nil, &apierr.Error{Code: apierr.InvalidBody, Message: fmt.Sprintf("ctx.request.json: the request body is not JSON (%v): %s", err, truncate(body, 120)), Hint: "Read ctx.request.body for a non-JSON payload, or check the Content-Type of the request before parsing."}
	}
	return normalize(v), nil
}
