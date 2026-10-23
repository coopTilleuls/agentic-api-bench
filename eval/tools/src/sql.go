package main

// Heuristic limits: SQL is found only in complete string/template/heredoc literals (Go backtick raw strings, Rust r#".."# raw strings included) (adjacent ones joined by + or .) starting like SELECT..FROM, INSERT INTO, UPDATE x SET, DELETE FROM or WITH x AS (; SQL built across variables or helpers is missed or split; regex vs division is guessed from the previous token; subqueries include CTE bodies; where predicates are and/or conditions per WHERE (a subquery counts its own); qb_calls are Doctrine QueryBuilder verbs in PHP.

import (
	"regexp"
	"sort"
	"strings"
)

type sqlResult struct {
	Statements int       `json:"sql_statements"`
	Joins      int       `json:"sql_joins"`
	Subqueries int       `json:"sql_subqueries"`
	Where      int       `json:"sql_where_predicates"`
	Tokens     int       `json:"sql_tokens"`
	QBCalls    int       `json:"qb_calls"`
	List       []sqlStmt `json:"statements,omitempty"`
}

type sqlStmt struct {
	Text       string `json:"text"`
	Joins      int    `json:"joins"`
	Subqueries int    `json:"subqueries"`
	Where      int    `json:"where_predicates"`
	Tokens     int    `json:"tokens"`
}

type literal struct {
	text       string
	start, end int
}

type lexCfg struct {
	slashComments bool
	hashComments  bool
	backtick      bool
	heredoc       bool // PHP <<<ID and shell <<ID
	triple        bool // """ and '''
	php           bool
	regex         bool // JS regex literals may hold quotes
	rawBacktick   bool // Go: backtick strings are raw, no escapes, no placeholders
	rust          bool // r#".."#, lifetimes vs char literals, nested block comments
}

func cfgFor(path string) (cfg lexCfg, whole bool) {
	switch ext(path) {
	case ".sql":
		return lexCfg{}, true
	case ".php":
		return lexCfg{slashComments: true, hashComments: true, heredoc: true, php: true}, false
	case ".sh", ".bash":
		return lexCfg{hashComments: true, heredoc: true}, false
	case ".star", ".py":
		return lexCfg{hashComments: true, triple: true}, false
	case ".json":
		return lexCfg{}, false
	case ".go":
		return lexCfg{slashComments: true, backtick: true, rawBacktick: true}, false
	case ".rs":
		return lexCfg{slashComments: true, rust: true}, false
	default: // ts tsx js mjs cjs
		return lexCfg{slashComments: true, backtick: true, regex: true}, false
	}
}

var (
	rustRawRe  = regexp.MustCompile(`^b?r(#*)"`)
	rustCharRe = regexp.MustCompile(`^'(?:\\[^']+|[^\\'])'`)
)

var heredocRe = regexp.MustCompile(`^<<<?-?\s*(?:'([A-Za-z_]\w*)'|"([A-Za-z_]\w*)"|([A-Za-z_]\w*))`)

// lexLiterals returns every string-like literal of src, outermost first
// by position; literals nested in a template placeholder are included.
func lexLiterals(src string, cfg lexCfg) []literal {
	var out []literal
	var scan func(i int, inPlaceholder bool) int
	scan = func(i int, inPlaceholder bool) int {
		depth := 0
		for i < len(src) {
			c := src[i]
			switch {
			case inPlaceholder && c == '{':
				depth++
				i++
			case inPlaceholder && c == '}':
				if depth == 0 {
					return i + 1
				}
				depth--
				i++
			case cfg.slashComments && strings.HasPrefix(src[i:], "//"):
				for i < len(src) && src[i] != '\n' {
					i++
				}
			case cfg.rust && strings.HasPrefix(src[i:], "/*"):
				nest := 1
				i += 2
				for i < len(src) && nest > 0 {
					switch {
					case strings.HasPrefix(src[i:], "/*"):
						nest++
						i += 2
					case strings.HasPrefix(src[i:], "*/"):
						nest--
						i += 2
					default:
						i++
					}
				}
			case cfg.slashComments && strings.HasPrefix(src[i:], "/*"):
				j := strings.Index(src[i+2:], "*/")
				if j < 0 {
					return len(src)
				}
				i += j + 4
			case cfg.regex && c == '/' && regexAllowed(src, i):
				i = skipRegex(src, i)
			case cfg.hashComments && c == '#' && !(cfg.php && i+1 < len(src) && src[i+1] == '['):
				for i < len(src) && src[i] != '\n' {
					i++
				}
			case cfg.heredoc && strings.HasPrefix(src[i:], "<<"):
				m := heredocRe.FindStringSubmatch(src[i:])
				if m == nil {
					i++
					continue
				}
				id := m[1] + m[2] + m[3]
				hs := i + len(m[0])
				nl := strings.IndexByte(src[hs:], '\n')
				if nl < 0 {
					return len(src)
				}
				bodyStart := hs + nl + 1
				endRe := regexp.MustCompile(`(?m)^[ \t]*` + regexp.QuoteMeta(id) + `\b`)
				loc := endRe.FindStringIndex(src[bodyStart:])
				if loc == nil {
					return len(src)
				}
				out = append(out, literal{src[bodyStart : bodyStart+loc[0]], i, bodyStart + loc[1]})
				i = bodyStart + loc[1]
			case cfg.triple && (strings.HasPrefix(src[i:], `"""`) || strings.HasPrefix(src[i:], `'''`)):
				q := src[i : i+3]
				j := strings.Index(src[i+3:], q)
				if j < 0 {
					return len(src)
				}
				out = append(out, literal{src[i+3 : i+3+j], i, i + 3 + j + 3})
				i += j + 6
			case cfg.rust && (c == 'r' || c == 'b') && (i == 0 || !isWordChar(src[i-1])) && rustRawRe.MatchString(src[i:]):
				m := rustRawRe.FindStringSubmatch(src[i:])
				bodyStart := i + len(m[0])
				closer := "\"" + m[1]
				j := strings.Index(src[bodyStart:], closer)
				if j < 0 {
					return len(src)
				}
				out = append(out, literal{src[bodyStart : bodyStart+j], i, bodyStart + j + len(closer)})
				i = bodyStart + j + len(closer)
			case cfg.rust && c == '\'':
				if m := rustCharRe.FindString(src[i:]); m != "" {
					i += len(m)
				} else {
					i++
				}
			case cfg.rawBacktick && c == '`':
				j := strings.IndexByte(src[i+1:], '`')
				if j < 0 {
					return len(src)
				}
				out = append(out, literal{src[i+1 : i+1+j], i, i + j + 2})
				i += j + 2
			case c == '\'' || c == '"' || (cfg.backtick && c == '`'):
				start := i
				i++
				var sb strings.Builder
				for i < len(src) {
					d := src[i]
					if d == '\\' {
						if i+1 < len(src) {
							sb.WriteByte(src[i+1])
						}
						i += 2
						continue
					}
					if d == c {
						i++
						break
					}
					if c == '`' && d == '$' && i+1 < len(src) && src[i+1] == '{' {
						sb.WriteString("${}")
						i = scan(i+2, true)
						continue
					}
					sb.WriteByte(d)
					i++
				}
				out = append(out, literal{sb.String(), start, i})
			default:
				i++
			}
		}
		return i
	}
	scan(0, false)
	sort.SliceStable(out, func(a, b int) bool { return out[a].start < out[b].start })
	return out
}

// joinLiterals merges top-level literals separated only by whitespace and one
// + (or . for PHP); literals nested inside another one stay standalone.
func joinLiterals(src string, lits []literal, cfg lexCfg) []string {
	var top []literal
	var res []string
	end := -1
	for _, l := range lits {
		if l.start < end {
			res = append(res, l.text)
			continue
		}
		top = append(top, l)
		end = l.end
	}
	for i := 0; i < len(top); i++ {
		cur := top[i].text
		for i+1 < len(top) {
			g := strings.TrimSpace(src[top[i].end:top[i+1].start])
			if g == "" || g == "+" || (cfg.php && g == ".") {
				cur += top[i+1].text
				i++
				continue
			}
			break
		}
		res = append(res, cur)
	}
	return res
}

var (
	startSelect = regexp.MustCompile(`(?is)^select\b`)
	hasFrom     = regexp.MustCompile(`(?i)\bfrom\b`)
	oneToken    = regexp.MustCompile(`(?is)^select\s+\S+\s*;?$`)
	startInsert = regexp.MustCompile(`(?is)^insert\s+into\b`)
	startUpdate = regexp.MustCompile(`(?is)^update\s+\S+(\s+(as\s+)?\w+)?\s+set\b`)
	startDelete = regexp.MustCompile(`(?is)^delete\s+from\b`)
	startWith   = regexp.MustCompile(`(?is)^with\s+(recursive\s+)?\w+(\s*\([^)]*\))?\s+as\s*(not\s+materialized\s*|materialized\s*)?\(`)
)

func isStatement(s string) bool {
	switch {
	case startSelect.MatchString(s):
		return hasFrom.MatchString(s) || oneToken.MatchString(s)
	case startInsert.MatchString(s), startUpdate.MatchString(s), startDelete.MatchString(s), startWith.MatchString(s):
		return true
	}
	return false
}

type tok struct {
	kind byte // w word, s literal/placeholder/number, p operator, ( ) ;
	text string
}

var multiOps = []string{"->>", "#>>", "::", "<=", ">=", "<>", "!=", "||", "->", "#>", "@>", "<@", "&&", ":="}

func tokenize(s string) []tok {
	var out []tok
	i := 0
	for i < len(s) {
		c := s[i]
		switch {
		case c == ' ' || c == '\t' || c == '\n' || c == '\r':
			i++
		case strings.HasPrefix(s[i:], "--"):
			for i < len(s) && s[i] != '\n' {
				i++
			}
		case strings.HasPrefix(s[i:], "/*"):
			j := strings.Index(s[i+2:], "*/")
			if j < 0 {
				i = len(s)
			} else {
				i += j + 4
			}
		case c == '\'' || c == '"':
			j := i + 1
			for j < len(s) {
				if s[j] == c {
					if j+1 < len(s) && s[j+1] == c {
						j += 2
						continue
					}
					break
				}
				j++
			}
			out = append(out, tok{'s', s[i:min(j+1, len(s))]})
			i = j + 1
		case strings.HasPrefix(s[i:], "${}"):
			out = append(out, tok{'s', "${}"})
			i += 3
		case c == '$' || c == '?' || (c == ':' && i+1 < len(s) && isWordStart(s[i+1]) && !(i > 0 && s[i-1] == ':')):
			j := i + 1
			for j < len(s) && isWordChar(s[j]) {
				j++
			}
			out = append(out, tok{'s', s[i:j]})
			i = j
		case isWordStart(c):
			j := i
			for j < len(s) && isWordChar(s[j]) {
				j++
			}
			out = append(out, tok{'w', strings.ToLower(s[i:j])})
			i = j
		case c >= '0' && c <= '9':
			j := i
			for j < len(s) && (isWordChar(s[j]) || s[j] == '.') {
				j++
			}
			out = append(out, tok{'s', s[i:j]})
			i = j
		case c == '(' || c == ')' || c == ';':
			out = append(out, tok{c, string(c)})
			i++
		default:
			matched := false
			for _, op := range multiOps {
				if strings.HasPrefix(s[i:], op) {
					out = append(out, tok{'p', op})
					i += len(op)
					matched = true
					break
				}
			}
			if !matched {
				out = append(out, tok{'p', string(c)})
				i++
			}
		}
	}
	return out
}

func isWordStart(c byte) bool {
	return c == '_' || c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= 0x80
}

func isWordChar(c byte) bool { return isWordStart(c) || c >= '0' && c <= '9' || c == '$' }

type frame struct {
	inWhere, between bool
}

var clauseEnd = map[string]bool{"group": true, "order": true, "having": true, "limit": true, "offset": true,
	"returning": true, "union": true, "intersect": true, "except": true, "window": true, "fetch": true}

func measure(toks []tok) sqlStmt {
	st := sqlStmt{Tokens: len(toks)}
	stack := []frame{{}}
	for i, t := range toks {
		top := &stack[len(stack)-1]
		switch t.kind {
		case '(':
			sub := i+1 < len(toks) && toks[i+1].kind == 'w' && (toks[i+1].text == "select" || toks[i+1].text == "with")
			if sub {
				st.Subqueries++
			}
			stack = append(stack, frame{inWhere: top.inWhere && !sub})
		case ')':
			if len(stack) > 1 {
				stack = stack[:len(stack)-1]
			}
		case 'w':
			switch {
			case t.text == "join":
				st.Joins++
			case t.text == "where":
				top.inWhere, top.between = true, false
				st.Where++
			case clauseEnd[t.text]:
				top.inWhere = false
			case top.inWhere && t.text == "between":
				top.between = true
			case top.inWhere && t.text == "and":
				if top.between {
					top.between = false
				} else {
					st.Where++
				}
			case top.inWhere && t.text == "or":
				st.Where++
			}
		}
	}
	return st
}

func stringValues(v any, out *[]string) {
	switch t := v.(type) {
	case string:
		*out = append(*out, t)
	case map[string]any:
		for _, c := range t {
			stringValues(c, out)
		}
	case map[any]any:
		for _, c := range t {
			stringValues(c, out)
		}
	case []any:
		for _, c := range t {
			stringValues(c, out)
		}
	}
}

func extractSQL(path, src string) []sqlStmt {
	cfg, whole := cfgFor(path)
	var chunks []string
	switch {
	case whole:
		chunks = []string{src}
	case ext(path) == ".yaml" || ext(path) == ".yml":
		if docs, err := decodeDocs(path, src); err == nil {
			for _, d := range docs {
				stringValues(d, &chunks)
			}
		}
	default:
		chunks = joinLiterals(src, lexLiterals(src, cfg), cfg)
	}
	var res []sqlStmt
	for _, ch := range chunks {
		var cur []tok
		flush := func() {
			defer func() { cur = nil }()
			if len(cur) == 0 {
				return
			}
			parts := make([]string, len(cur))
			for k, t := range cur {
				parts[k] = t.text
			}
			text := strings.Join(parts, " ")
			if isStatement(text) {
				st := measure(cur)
				st.Text = text
				res = append(res, st)
			}
		}
		depth := 0
		for _, t := range tokenize(ch) {
			switch {
			case t.kind == '(':
				depth++
			case t.kind == ')' && depth > 0:
				depth--
			case t.kind == ';' && depth == 0:
				flush()
				continue
			}
			cur = append(cur, t)
		}
		flush()
	}
	return res
}

var qbRe = regexp.MustCompile(`->\s*(createQueryBuilder|select|addSelect|from|innerJoin|leftJoin|rightJoin|join|where|andWhere|orWhere|groupBy|addGroupBy|having|andHaving|orHaving|orderBy|addOrderBy|setParameter|setParameters|setMaxResults|setFirstResult)\s*\(`)

func countQB(path, src string) int {
	if ext(path) != ".php" {
		return 0
	}
	cfg, _ := cfgFor(path)
	b := []byte(src)
	for _, l := range lexLiterals(src, cfg) {
		for k := l.start; k < l.end && k < len(b); k++ {
			b[k] = ' '
		}
	}
	return len(qbRe.FindAllIndex(b, -1))
}

func runSQL(file, base string, verbose bool) (any, error) {
	src, err := readFile(file)
	if err != nil {
		return nil, err
	}
	stmts, qb := extractSQL(file, src), countQB(file, src)
	if base != "" {
		osrc, err := readFile(base)
		if err != nil {
			return nil, err
		}
		have := map[string]int{}
		for _, s := range extractSQL(base, osrc) {
			have[s.Text]++
		}
		var added []sqlStmt
		for _, s := range stmts {
			if have[s.Text] > 0 {
				have[s.Text]--
				continue
			}
			added = append(added, s)
		}
		stmts = added
		qb = max(qb-countQB(base, osrc), 0)
	}
	r := sqlResult{QBCalls: qb}
	for _, s := range stmts {
		r.Statements++
		r.Joins += s.Joins
		r.Subqueries += s.Subqueries
		r.Where += s.Where
		r.Tokens += s.Tokens
	}
	if verbose {
		r.List = stmts
	}
	return r, nil
}

// regexAllowed: a "/" opens a regex literal when the previous significant
// character cannot end an expression (comments are skipped before we get here).
func regexAllowed(src string, i int) bool {
	j := i - 1
	for j >= 0 && (src[j] == ' ' || src[j] == '\t' || src[j] == '\n' || src[j] == '\r') {
		j--
	}
	if j < 0 {
		return true
	}
	if strings.IndexByte("(,=:[!&|?{};+-*%<>~^", src[j]) >= 0 {
		return true
	}
	k := j
	for k >= 0 && isWordChar(src[k]) {
		k--
	}
	switch src[k+1 : j+1] {
	case "return", "typeof", "case", "in", "of", "void", "delete", "throw", "else":
		return true
	}
	return false
}

func skipRegex(src string, i int) int {
	i++
	inClass := false
	for i < len(src) && src[i] != '\n' {
		switch {
		case src[i] == '\\':
			i++
		case src[i] == '[':
			inClass = true
		case src[i] == ']':
			inClass = false
		case src[i] == '/' && !inClass:
			return i + 1
		}
		i++
	}
	return i
}
