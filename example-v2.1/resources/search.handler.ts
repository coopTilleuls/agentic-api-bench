// strpos instead of LIKE: no wildcard to escape.
export default function search(text: string, ctx) {
  const needle = text.toLowerCase();
  return ctx.sql`(strpos(lower(${ctx.property("title")}), ${needle}) > 0 OR strpos(lower(${ctx.property("author.name")}), ${needle}) > 0)`;
}
