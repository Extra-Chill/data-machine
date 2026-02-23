/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
export interface User {
    id: number;
    name: string;
    email: string;
}
export interface UserData {
    username: string;
    email: string;
    firstName?: string;
    lastName?: string;
    password?: string;
    roles?: string[];
}
export interface UserRequestData {
    username: string;
    email: string;
    first_name?: string;
    last_name?: string;
    password?: string;
    roles?: string[];
}
/**
 * Add a test user.
 *
 * @see https://developer.wordpress.org/rest-api/reference/users/#create-a-user
 * @param this
 * @param user User data to create.
 */
declare function createUser(this: RequestUtils, user: UserData): Promise<User>;
/**
 * Delete all users except main root user.
 *
 * @param this
 */
declare function deleteAllUsers(this: RequestUtils): Promise<any[]>;
export { createUser, deleteAllUsers };
//# sourceMappingURL=users.d.ts.map