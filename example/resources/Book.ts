export default defineResource({
  name: "Book",
  path: "/books",
  query: "book.query.ts",
  command: "book.command.ts",
  itemsPerPage: 2,
  input: {
    title: { type: "string", required: true },
    author_id: { type: "integer", required: true },
    published: { type: "boolean" },
  },
  properties: {
    id: { type: "integer", identifier: true },
    title: { type: "string" },
    author: { type: "string", filterable: true },
    published: { type: "boolean" },
  },
});
