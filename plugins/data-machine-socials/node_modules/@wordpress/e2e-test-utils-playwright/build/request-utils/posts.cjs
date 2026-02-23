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

// packages/e2e-test-utils-playwright/src/request-utils/posts.ts
var posts_exports = {};
__export(posts_exports, {
  createPost: () => createPost,
  deleteAllPosts: () => deleteAllPosts
});
module.exports = __toCommonJS(posts_exports);
async function deleteAllPosts(postType = "posts") {
  const posts = await this.rest({
    path: `/wp/v2/${postType}`,
    params: {
      per_page: 100,
      // All possible statuses.
      status: "publish,future,draft,pending,private,trash"
    }
  });
  await Promise.all(
    posts.map(
      (post) => this.rest({
        method: "DELETE",
        path: `/wp/v2/${postType}/${post.id}`,
        params: {
          force: true
        }
      })
    )
  );
}
async function createPost(payload) {
  const post = await this.rest({
    method: "POST",
    path: `/wp/v2/posts`,
    data: { ...payload }
  });
  return post;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createPost,
  deleteAllPosts
});
//# sourceMappingURL=posts.cjs.map
