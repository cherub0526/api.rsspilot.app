## ADDED Requirements

### Requirement: Authenticated user can delete all their chat sessions
The system SHALL provide an endpoint `DELETE /v1/users/sessions` that soft-deletes all `ChatSession` records belonging to the authenticated user, regardless of which media they are associated with.

#### Scenario: Successful deletion with existing sessions
- **WHEN** an authenticated user sends `DELETE /v1/users/sessions`
- **THEN** all chat sessions owned by that user are soft-deleted
- **AND** the system returns HTTP 204 No Content with an empty body

#### Scenario: Idempotent — no sessions exist
- **WHEN** an authenticated user sends `DELETE /v1/users/sessions` and they have no active sessions
- **THEN** the system returns HTTP 204 No Content without error

#### Scenario: Unauthenticated request is rejected
- **WHEN** a request is sent to `DELETE /v1/users/sessions` without a valid Bearer token
- **THEN** the system returns HTTP 401 Unauthorized

#### Scenario: Sessions of other users are not affected
- **WHEN** user A sends `DELETE /v1/users/sessions`
- **THEN** only sessions owned by user A are soft-deleted
- **AND** sessions owned by other users remain intact
