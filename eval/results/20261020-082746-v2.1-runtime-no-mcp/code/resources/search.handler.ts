export default function search(value: string, ctx) {
  return ctx.sql`(strpos(lower(${ctx.property("title")}), lower(${value})) > 0 OR strpos(lower(${ctx.property("author.name")}), lower(${value})) > 0)`;
}
