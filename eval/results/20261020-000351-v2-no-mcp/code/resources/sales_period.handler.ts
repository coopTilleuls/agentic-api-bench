export default function salesPeriod(value: string, ctx) {
  if (ctx.get("from") > value) {
    ctx.reject({ message: "from must not be after to", pointer: "/to", hint: "Use from <= to, both YYYY-MM-DD." });
  }
  return null;
}
