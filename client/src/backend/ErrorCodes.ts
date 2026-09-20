/**
 * Custom error codes for the corrai API
 * 
 * These error codes are used by the server to indicate specific error conditions
 * that go beyond standard HTTP status codes.
 */

/**
 * Custom error code: Exam has already been claimed
 * Status: Conflict (432)
 */
export const ERROR_EXAM_ALREADY_CLAIMED = 432;

/**
 * Custom error code: Request author does not match the locker
 * Status: Forbidden (433)
 */
export const ERROR_LOCKER_MISMATCH = 433;

/**
 * Custom error code: No tries remaining for this exam
 * Status: Forbidden (434)
 */
export const ERROR_NO_TRIES_REMAINING = 434;

/**
 * Custom error code: Invalid claim secret (tries_remaining is decremented)
 * Status: Unprocessable Entity (435)
 */
export const ERROR_INVALID_CLAIM_SECRET = 435;

/**
 * Custom error code: Cannot delete exam - exam is locked or claimed
 * Status: Conflict (436)
 */
export const ERROR_EXAM_LOCKED_OR_CLAIMED = 436;

/**
 * Custom error code: Cannot delete exam - request author is not the exam author
 * Status: Forbidden (437)
 */
export const ERROR_NOT_EXAM_AUTHOR = 437;

/**
 * Type for all custom error codes
 */
export type CustomErrorCode =
  | typeof ERROR_EXAM_ALREADY_CLAIMED
  | typeof ERROR_LOCKER_MISMATCH
  | typeof ERROR_NO_TRIES_REMAINING
  | typeof ERROR_INVALID_CLAIM_SECRET
  | typeof ERROR_EXAM_LOCKED_OR_CLAIMED
  | typeof ERROR_NOT_EXAM_AUTHOR;

/**
 * Checks if a given status code is a custom error code
 */
export function isCustomErrorCode(code: number): code is CustomErrorCode {
  return code >= 432 && code <= 437;
}

