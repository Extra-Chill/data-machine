"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/e2e-test-utils-playwright/src/request-utils/comments.ts
var comments_exports = {};
__export(comments_exports, {
  createComment: () => createComment,
  deleteAllComments: () => deleteAllComments
});
module.exports = __toCommonJS(comments_exports);
async function createComment(payload) {
  const currentUser = await this.rest({
    path: "/wp/v2/users/me",
    method: "GET"
  });
  const author = currentUser.id;
  const comment = await this.rest({
    method: "POST",
    path: "/wp/v2/comments",
    data: { ...payload, author }
  });
  return comment;
}
async function deleteAllComments(type) {
  const comments = await this.rest({
    path: "/wp/v2/comments",
    params: {
      per_page: 100,
      status: "all",
      type: type || "comment"
    }
  });
  await Promise.all(
    comments.map(
      (comment) => this.rest({
        method: "DELETE",
        path: `/wp/v2/comments/${comment.id}`,
        params: {
          force: true
        }
      })
    )
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createComment,
  deleteAllComments
});
//# sourceMappingURL=comments.cjs.map
