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

// packages/e2e-test-utils-playwright/src/request-utils/users.ts
var users_exports = {};
__export(users_exports, {
  createUser: () => createUser,
  deleteAllUsers: () => deleteAllUsers
});
module.exports = __toCommonJS(users_exports);
async function listUsers() {
  const response = await this.rest({
    method: "GET",
    path: "/wp/v2/users",
    params: {
      per_page: 100
    }
  });
  return response;
}
async function createUser(user) {
  const userData = {
    username: user.username,
    email: user.email
  };
  if (user.firstName) {
    userData.first_name = user.firstName;
  }
  if (user.lastName) {
    userData.last_name = user.lastName;
  }
  if (user.password) {
    userData.password = user.password;
  }
  if (user.roles) {
    userData.roles = user.roles;
  }
  const response = await this.rest({
    method: "POST",
    path: "/wp/v2/users",
    data: userData
  });
  return response;
}
async function deleteUser(userId) {
  const response = await this.rest({
    method: "DELETE",
    path: `/wp/v2/users/${userId}`,
    params: { force: true, reassign: 1 }
  });
  return response;
}
async function deleteAllUsers() {
  const users = await listUsers.bind(this)();
  const responses = await Promise.all(
    users.filter(
      (user) => user.id !== 1 && user.name !== this.user.username
    ).map((user) => deleteUser.bind(this)(user.id))
  );
  return responses;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createUser,
  deleteAllUsers
});
//# sourceMappingURL=users.cjs.map
