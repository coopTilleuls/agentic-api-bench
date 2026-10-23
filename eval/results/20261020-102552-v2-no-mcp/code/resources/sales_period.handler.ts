export default function period(value, ctx) {
  const from = ctx.get("from");
  if (from > value) ctx.reject({ message: "from must not be after to", pointer: "/to" });
  return null;
}
