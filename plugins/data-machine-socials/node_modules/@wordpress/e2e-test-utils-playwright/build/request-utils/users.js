"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createUser = createUser;
exports.deleteAllUsers = deleteAllUsers;
/**
 * List all users.
 *
 * @see https://developer.wordpress.org/rest-api/reference/users/#list-users
 * @param this
 */
async function listUsers() {
    const response = await this.rest({
        method: 'GET',
        path: '/wp/v2/users',
        params: {
            per_page: 100,
        },
    });
    return response;
}
/**
 * Add a test user.
 *
 * @see https://developer.wordpress.org/rest-api/reference/users/#create-a-user
 * @param this
 * @param user User data to create.
 */
async function createUser(user) {
    const userData = {
        username: user.username,
        email: user.email,
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
        method: 'POST',
        path: '/wp/v2/users',
        data: userData,
    });
    return response;
}
/**
 * Delete a user.
 *
 * @see https://developer.wordpress.org/rest-api/reference/users/#delete-a-user
 * @param this
 * @param userId The ID of the user.
 */
async function deleteUser(userId) {
    const response = await this.rest({
        method: 'DELETE',
        path: `/wp/v2/users/${userId}`,
        params: { force: true, reassign: 1 },
    });
    return response;
}
/**
 * Delete all users except main root user.
 *
 * @param this
 */
async function deleteAllUsers() {
    const users = await listUsers.bind(this)();
    // The users endpoint doesn't support batch request yet.
    const responses = await Promise.all(users
        // Do not delete neither root user nor the current user.
        .filter((user) => user.id !== 1 && user.name !== this.user.username)
        .map((user) => deleteUser.bind(this)(user.id)));
    return responses;
}
//# sourceMappingURL=users.js.map