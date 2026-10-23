# Prior art: context files, framework adoption and backend generation by coding agents

Search date: 2026-10-23. Every entry below was opened (arXiv abstract page, HTML, or the post itself) unless it is marked "search snippet only". "Preprint" means arXiv with no peer-reviewed venue listed on the abstract page.

Relation tags: SAME = asks our question. ADJ = adjacent. SUP = supports one of our findings. CON = contradicts or complicates one.

---

## 1. Effect of AGENTS.md / CLAUDE.md / context files

**Gloaguen, Mündler-Sasahara, Müller, Raychev, Vechev. "Evaluating AGENTS.md: Are Repository-Level Context Files Helpful for Coding Agents?" arXiv:2602.11988, v1 12 Feb 2026, v3 29 Sep 2026. Preprint** (search results also show ICLR 2026 virtual pages, which I did not verify).
https://arxiv.org/abs/2602.11988
The authors ran SWE-bench tasks with LLM-generated context files, plus a new set of issues from repos that ship developer-written ones. Context files generally do not improve success and raise inference cost by more than 20%. Agents follow the instructions, but repository overviews do not help. The authors recommend keeping only task-specific instructions.
Relation: SAME question, closest prior work. SUP for our cost increase. CON on usefulness, but they test repository overviews on bug-fix tasks. We test a decision-first file on greenfield framework use and measure framework adoption, which they do not.

**Lulla, Mohsenimofidi, Galster, Zhang, Baltes, Treude. "On the Impact of AGENTS.md Files on the Efficiency of AI Coding Agents." arXiv:2601.20404, v1 28 Jan 2026, v2 30 Mar 2026.** A 5-page paper; search snippets list it at ICSE JAWs 2026, which the abstract page does not show.
https://arxiv.org/abs/2601.20404
They ran Codex and Claude Code on 124 PRs in 10 repos, with and without AGENTS.md. With the file, median runtime fell about 28.6% and output tokens about 16.6%, with comparable completion.
Relation: SAME question, efficiency axis. CON to our "higher token cost" result, so the sign of the cost effect is contested in the literature. Our result adds a mechanism: the extra tokens are spent on using the framework.

**Khatri. "Do Context Files Help Coding Agents? A Two-Agent Ablation Study on Real Repositories." arXiv:2607.27250, 28 Jul 2026. Preprint, single author.**
https://arxiv.org/abs/2607.27250
The study uses Claude Code and Codex on 17 tasks from 3 repos, 288 runs. Context strategy does not measurably change correctness, with an equivalence bound of about 10–15 pp. Failures come from implementation skill (pattern selection, wiring), not missing repo knowledge. Task difficulty is agent-specific, which the author offers as an explanation for contradictory prior results.
Relation: SAME, CON on correctness. It supports framing our result as an effect on *how* agents build (pattern selection), not *whether* they pass.

**Chatlatanagulchai, Li, Kashiwa, Reid, Thonglek, Leelaprute, Rungsawang, Manaskasemsak, Adams, Hassan, Iida. "Agent READMEs: An Empirical Study of Context Files for Agentic Coding." arXiv:2511.12884, v1 17 Nov 2025, v2 9 Aug 2026. Preprint.**
https://arxiv.org/abs/2511.12884
The authors analyse 2,303 context files from 1,925 repos. The files grow like configuration code. The most common content is test procedures (75.9%), implementation details (70.8%) and architecture (68.1%). Security (14.8%) and performance (14.5%) are rare.
Relation: ADJ (what people write, not its effect). Useful to contrast typical "overview" files with our decision-first file.

Not read, seen in search only: a study of 253 CLAUDE.md files (arXiv:2509.14744).

## 2. Skills, tool docs not invoked, llms.txt

**Jude Gao (Vercel). "AGENTS.md outperforms skills in our agent evals." Vercel blog, 27 Jan 2026.**
https://vercel.com/blog/agents-md-outperforms-skills-in-our-agent-evals
The evals cover Next.js 16 APIs absent from training data. The skill was never invoked in 56% of cases. Pass rates: baseline 53%, skill 53%, skill plus an explicit "use it" instruction 79%, and an 8 KB compressed docs index in AGENTS.md 100%. The post names no models and reports no token data.
Relation: SAME question (framework vendor, official skill vs AGENTS.md). SUP for our skills-plugin vs AGENTS.md result. Vendor post, not peer-reviewed.

**Li, Liu, Chen et al. (77 authors). "SkillsBench: Benchmarking How Well Agent Skills Work Across Diverse Tasks." arXiv:2602.12670, v1 13 Feb 2026, v4 14 Jun 2026. Preprint** (search shows a NeurIPS 2026 poster page, not verified).
https://arxiv.org/abs/2602.12670
The benchmark has 87 tasks in 8 domains across 18 model-harness configs. Curated skills raise pass rates from 33.9% to 50.5%. Focused skills (3 modules or fewer) beat exhaustive bundles. Self-generated skills give little benefit, per search summaries of v1–v2.
Relation: ADJ. It partly CONtradicts "skills don't help". Its "focused beats exhaustive" result SUPports our short task-targeted AGENTS.md.

**Han, Zhang, Song, Fang, Chen, Sun, Hu. "SWE-Skills-Bench: Do Agent Skills Actually Help in Real-World Software Engineering?" arXiv:2603.15401, 16 Mar 2026. Preprint.**
https://arxiv.org/abs/2603.15401
They test 49 public skills on about 565 SE tasks. 39 of 49 skills give zero improvement, with an average of +1.2%. Three skills hurt (down to -10%) because version-mismatched guidance conflicted with the project. Token overhead ranges from modest savings to +451% with unchanged pass rates.
Relation: SUP (skills rarely help in SE, can cost tokens).

**Aliyan Ishfaq (LangChain). "How to turn Claude Code into a domain specific coding agent." LangChain blog, 11 Sep 2025.**
https://www.langchain.com/blog/how-to-turn-claude-code-into-a-domain-specific-coding-agent
They used Claude 4 Sonnet on 3 LangGraph tasks and compared vanilla, an llms.txt docs MCP (MCPDoc), a condensed Claude.md, and both together. Claude.md beat MCP docs and was about 2.5x cheaper on one task. llms.txt via MCP added about 10 pp. The agent often fetched only the top page and stopped. The combination scored best.
Relation: SAME shape (framework vendor, condensed guide vs docs access), on an agent framework rather than a web framework. SUP. Vendor post.

**LangChain Team. "LangChain Skills" announcement, 4 Mar 2026.**
https://www.langchain.com/blog/langchain-skills
The post announces 11 skills and reports Claude Code (Sonnet 4.6) going from 29% (25% in the table) to 95% on an unpublished eval. It gives no invocation-rate or token data.
Relation: CON to Vercel and to our skills result, but the evidence is weak (unpublished eval, inconsistent figures).

**Jeremy Howard. "The /llms.txt file" proposal, llmstxt.org, 3 Sep 2024 (v2, modified 10 Aug 2026).**
https://llmstxt.org/
It specifies a curated markdown index. The page does not mention llms-full.txt and reports no measured effect, only adoption.
Relation: background. I found **no controlled study of llms.txt / llms-full.txt effect on coding agents**. The measurements I saw are crawler-log analyses (Ahrefs, OtterlyAI, SE Ranking), and I read them only through vendor or SEO secondary coverage (e.g. https://ppc.land/llms-txt-adoption-rises-8-8x-but-97-of-files-get-zero-ai-requests/). Do not cite them as research.

## 3. Benchmarks: agents building backends/APIs from a spec

**Vero, Mündler, Chibotaru, Raychev, Baader, Jovanović, He, Vechev. "BaxBench: Can LLMs Generate Correct and Secure Backends?" arXiv:2502.11844, v3 30 May 2025; ICML 2025 (PMLR 267, per proceedings.mlr.press listing).**
https://arxiv.org/abs/2502.11844
The benchmark has 392 tasks: 28 scenarios × 14 backend frameworks in 6 languages, with tests plus end-to-end exploits. The best model (o1) reaches about 62% correctness, and about half of the correct programs are exploitable. Models struggle more on less popular frameworks.
Relation: ADJ, closest benchmark design (spec → backend, black-box). SUP for "framework popularity matters". It does not measure whether the framework is used as designed, and it uses single-shot LLMs, not context variations.

**Dente, Satriani, Papotti. "Constraint Decay: The Fragility of LLM Agents in Backend Code Generation." arXiv:2605.06445, v1 7 May 2026, v2 18 Sep 2026; COLM 2026 (per EURECOM page https://www.eurecom.fr/en/node/4925714).**
https://arxiv.org/abs/2605.06445
The study has 80 greenfield and 20 feature tasks with a fixed API contract across 8 frameworks (Flask, FastAPI, Django, aiohttp, Express, Fastify, Hono, Koa). Agents were Mini-SWE-Agent and OpenHands with 8 models. Assertion pass rates drop 27.28 pp on average as structural constraints (architecture, DB, ORM) are added. Mid-tier models do better on minimal Flask than on convention-heavy FastAPI and Django. Data-layer and ORM errors dominate. A regex verifier checks ORM use vs raw SQL, a weak "used as designed" signal. Token usage rises with constraint level.
Relation: closest ADJ/near-SAME. It is the only work found that checks whether agents comply with framework structure. It SUPports "convention-heavy frameworks are harder". Our study differs: we vary context rather than constraints, we include batteries-included declarative frameworks (API Platform, our runtime), and we measure framework bypass explicitly.

**Guo, Wu, Cao, Wei, Ran, Yang, Xie. "BackendForge: Benchmarking Agentic End-to-End Code Generation with Backend Services." arXiv:2607.11042, 13 Jul 2026. Preprint.**
https://arxiv.org/abs/2607.11042
The benchmark has 56 tasks from real apps, with a spec plus an OpenAPI contract, a Dockerized service, and black-box HTTP tests only. GPT-5.5 scores 55.4% under the base oracle and 28.6% under the final one. It does not compare frameworks.
Relation: ADJ. It uses near-identical methodology to our scoring (OpenAPI contract, Docker, black-box HTTP), so cite it as methodological precedent.

**Xu, Mao, Guan, Feng. "Web-Bench: A LLM Code Benchmark Based on Web Standards and Frameworks." arXiv:2505.07473, 12 May 2025. Preprint.**
https://arxiv.org/abs/2505.07473
The benchmark has 50 projects × 20 sequential tasks. Claude 3.7 Sonnet reaches 25.1% Pass@1. The abstract does not compare frameworks head to head. A search snippet mentions per-framework scores (Next.js, Express, Fastify…), which I did not verify in the paper.
Relation: ADJ.

FullStack Bench (arXiv:2412.00535) and WebApp1K (arXiv:2408.00019, Yi Cui, Jul 2024) were seen in search only, not read. Both are LLM code-gen benchmarks (multi-language snippets; React components from tests), and neither is an agent+framework study. No "DevBench" software benchmark was found.

## 4. Library/framework adoption, popularity bias, hallucination, version drift

**Twist, Harman, Syme, Noppen, Yannakoudakis, Nauck, Zhang. "A Study of LLMs' Preferences for Libraries and Programming Languages." arXiv:2503.17181, v1 21 Mar 2025, v4 4 Jun 2026; Findings of ACL 2026.**
https://arxiv.org/abs/2503.17181
The study covers 8 LLMs. They overuse popular libraries (NumPy in up to 45% of cases where it is not needed) and default to Python even for high-performance setups, never choosing Rust. Models favour familiarity over suitability.
Relation: SUP for the training-data bias explanation of why agents fall back to generic code instead of a less familiar framework.

**Gu, Liang, Ma, Li. "The Matthew Effect of AI Programming Assistants: A Hidden Bias in Software Evolution." arXiv:2509.23261, v1 27 Sep 2025, v3 9 Feb 2026. Preprint.**
https://arxiv.org/abs/2509.23261
Over thousands of algorithmic tasks and hundreds of framework-selection tasks, mainstream languages and frameworks get significantly higher success rates than niche ones. The authors call this a feedback loop. The abstract gives no figures.
Relation: SUP and ADJ. It is the strongest statement that framework popularity drives agent success, and it raises the stakes for framework authors, which motivates our study.

**Zhang, Jiang, Ma, Bao, Lin, Shen, Li, Zhai. "Rethinking Technology Stack Selection with AI Coding Proficiency." arXiv:2509.11132, v1 14 Sep 2025, v2 24 Jun 2026. Preprint.**
https://arxiv.org/abs/2509.11132
The study covers 170 libraries and 6 LLMs. Functionally similar libraries differ by up to 84% in the quality of LLM-generated code. The authors propose "AI coding proficiency" as a technology-selection criterion.
Relation: SUP and ADJ. It gives a name to what our cross-stack comparison measures for whole frameworks.

**Spracklen, Wijewickrama, Sakib, Maiti, Viswanath, Jadliwala. "We Have a Package for You! A Comprehensive Analysis of Package Hallucinations by Code Generating LLMs." arXiv:2406.10279, v3 2 Mar 2025; USENIX Security 2025.**
https://arxiv.org/abs/2406.10279
The study covers 16 LLMs and 576k samples. Hallucinated packages appear in at least 5.2% of samples for commercial models and 21.7% for open models, with 205,474 unique fake names.
Relation: ADJ (hallucinated APIs). Background only.

**Cipollone, Titov, Izadi, Bogomolov, van Deursen. "LibEvoBench: Probing Temporal Knowledge Stratification in Code Generation Models." arXiv:2606.25402, 24 Jun 2026; DL4Code workshop @ ICML 2026.**
https://arxiv.org/abs/2606.25402
Models are largely version-oblivious. Stating the target version does not help, while relevant documentation significantly helps.
Relation: SUP. Supplied docs beat naming the version, which is consistent with why a targeted AGENTS.md works for recent framework versions (API Platform 4.x, Next.js 16).

**Sharma, Thorat, Chopra. "Frontier Coding Agents Use Metaprogramming to Adapt to Unfamiliar Programming Languages." arXiv:2606.10933, 9 Jun 2026. Preprint.**
https://arxiv.org/abs/2606.10933
On esoteric languages, Opus 4.6 and GPT-5.4 avoid writing the target language and instead write Python generators. Blocking this hurts performance a lot. Haiku 4.5 stays low.
Relation: ADJ and SUP by analogy. Faced with an unfamiliar target, strong agents route around it in a familiar tool, which mirrors our "bypass the framework with hand-written controllers" behaviour.

Not read, seen in search only: "Learning to Commit" (arXiv:2603.26664), whose abstract per OpenTrain says agent PRs duplicate existing internal APIs and ignore conventions. It is relevant to bypass. Read it before citing.

## 5. Agent-first / agent-friendly API and tool design (AX)

**Yang, Jimenez, Wettig, Lieret, Yao, Narasimhan, Press. "SWE-agent: Agent-Computer Interfaces Enable Automated Software Engineering." arXiv:2405.15793, v3 11 Nov 2024** (the abstract page lists no venue; it was published at NeurIPS 2024, which I did not verify on the page).
https://arxiv.org/abs/2405.15793
LM agents are a new kind of end user, and the design of their interface measurably changes performance.
Relation: foundational SUP for "agent-first framework" design. Cite it as the research root of AX.

**Ken Aizawa (Anthropic). "Writing effective tools for agents — with agents." Anthropic Engineering, 11 Sep 2025.**
https://www.anthropic.com/engineering/writing-tools-for-agents
The post recommends few consolidated tools, namespacing, high-signal concise responses, actionable error messages instead of tracebacks, pagination and truncation for token budget, and a concise vs detailed response format (72 vs 206 tokens in its example).
Relation: practitioner guidance for our agent-first runtime design (errors, token budget). Not a study.

**Mathias Biilmann. "Introducing AX: Why Agent Experience Matters." biilmann.blog, 28 Jan 2025.**
https://biilmann.blog/articles/introducing-ax/
The post coins "agent experience": products should be designed for agents as users.
Relation: terminology origin. Opinion piece.

Other AX posts (Speakeasy, Nordic APIs, WorkOS, agentpatterns.ai) were seen in search only. They are vendor and blog content and not worth citing.

## 6. Cost / token efficiency as an outcome

**Bai, Huang, Wang, Sun, Mihalcea, Brynjolfsson, Pentland, Pei. "How Do AI Agents Spend Your Money? Analyzing and Predicting Token Consumption in Agentic Coding Tasks." arXiv:2604.22750, v1 24 Apr 2026, v3 2 Oct 2026. Preprint** (search shows a NeurIPS 2026 page, not verified).
https://arxiv.org/abs/2604.22750
The study covers 8 frontier LLMs on SWE-bench Verified. Agentic tasks use about 1000x the tokens of code chat, mostly input tokens. The same task varies up to 30x across runs. Accuracy often peaks at intermediate cost. Models predict their own cost poorly (r ≤ 0.39).
Relation: SUP for treating cost as a first-class outcome. The 30x run-to-run variance is a caution for our per-cell sample sizes.

**Gao, Peng. "More with Less: An Empirical Study of Turn-Control Strategies for Efficient Coding Agents." arXiv:2510.16786, v2 25 Nov 2025. Preprint.**
https://arxiv.org/abs/2510.16786
On SWE-bench, capping turns at the baseline's 75th percentile cuts cost by 24–68% with minimal loss in solve rate.
Relation: ADJ (cost and turns as outcomes, turns as cost driver).

Also relevant on cost: Gloaguen et al. (more than 20% cost increase from context files), Lulla et al. (16.6% fewer output tokens), SWE-Skills-Bench (up to +451% tokens), LangChain (Claude.md about 2.5x cheaper than MCP docs), and Constraint Decay (tokens rise with constraint level).

---

## (a) Verdict

The question is partly covered, not answered. Three strands exist separately:
1. The effect of context files on agent success and cost (Gloaguen; Lulla; Khatri), with contradictory results and all on bug-fix or PR tasks in existing repos.
2. Vendor evals showing condensed AGENTS.md beats skills or docs tools for one framework (Vercel/Next.js; LangChain).
3. Benchmarks of agents generating backends across frameworks (BaxBench, Constraint Decay, BackendForge), which show popularity and convention effects but vary model and constraints, not context.

No work found combines: greenfield spec-to-API tasks across many stacks, varying the context source (none / official AGENTS.md / official skills / task-targeted file), and measuring *framework adoption vs bypass* as an outcome alongside correctness and cost. Our study adds that outcome variable and the cross-stack plus agent-first-runtime comparison. As a side result, framework bugs found by agents and fixed upstream are new as a contribution type.

Framing it as research is defensible as an empirical study or workshop-style paper. Address sample size given the 30x token variance (Bai et al.), and state that our "higher cost" result conflicts with Lulla et al. while agreeing with Gloaguen et al.

## (b) References to cite, ranked

1. **Gloaguen et al. 2026, arXiv:2602.11988.** Use in the AGENTS.md article as the main counterpoint: context files do not raise success and cost more than 20%, while our decision-first file changes *how* agents build.
2. **Dente, Satriani, Papotti, COLM 2026, arXiv:2605.06445.** Use in the cross-stack article as the closest methodology (fixed API contract, 8 frameworks, ORM-use verifier), showing convention-heavy frameworks are harder, which our bypass metric explains.
3. **Vercel, Gao, 27 Jan 2026.** Use in the skills vs AGENTS.md section: skills not invoked in 56% of cases, and the AGENTS.md index reached 100%. This is industry precedent our result reproduces on other frameworks.
4. **Lulla et al. 2026, arXiv:2601.20404.** Use in the cost section as the contradicting efficiency result (fewer tokens with AGENTS.md), to discuss why our targeted file costs more.
5. **Vero et al., BaxBench, ICML 2025.** Use when introducing the benchmark design (spec → backend across 14 frameworks, black-box tests), noting it found weaker results on less popular frameworks.
6. **Twist et al., Findings of ACL 2026, arXiv:2503.17181** (or **Gu et al., arXiv:2509.23261, Matthew Effect**). Use in the "why agents bypass the framework" discussion for the familiarity and popularity bias.
7. **SWE-Skills-Bench, Han et al., arXiv:2603.15401.** Use next to Vercel: 39 of 49 skills give zero gain and token overhead reaches +451%.
8. **Yang et al., SWE-agent, arXiv:2405.15793** (plus Anthropic's tool-writing post for practice). Use in the agent-first runtime section: interface design for agents measurably changes performance.

Honourable mentions: Khatri arXiv:2607.27250 (agent-specific difficulty explains contradictions), BackendForge arXiv:2607.11042 (same OpenAPI+Docker+HTTP scoring), Bai et al. arXiv:2604.22750 (token variance), Sharma et al. arXiv:2606.10933 (agents route around unfamiliar targets).
