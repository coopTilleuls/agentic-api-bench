export default function search(value: string, ctx) {
  const pattern = `%${value.replace(/[\\%_]/g, "\\$&")}%`;
  return ctx.sql`(${ctx.property("title")} ILIKE ${pattern} OR ${ctx.property("author.name")} ILIKE ${pattern})`;
}
