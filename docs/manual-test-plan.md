# Manual acceptance test plan

Run these checks after installing and creating two or more test users. Record the actual result/date in your project report; do not claim a test passed until you run it.

| ID | Area | Test | Expected result |
|---|---|---|---|
| T01 | Setup | Create first administrator with valid details | Account is created and user can sign in |
| T02 | Setup | Open setup after an account exists | Setup redirects to sign-in |
| T03 | Authentication | Sign in with a wrong password | Access is denied with a generic error |
| T04 | Authentication | Sign out then open dashboard | User is redirected to sign-in |
| T05 | Users | Admin creates a project manager and a worker | Both accounts appear with correct roles |
| T06 | Access | Worker opens user management URL | Access denied |
| T07 | Projects | Create project with valid date range | Project appears in project register |
| T08 | Validation | Set project end date earlier than start date | Form is rejected with an error message |
| T09 | Tasks | Create task and assign worker | Task appears with correct project, assignee and due date |
| T10 | Access | Worker views task register | Only tasks assigned to that worker are shown |
| T11 | Progress | Worker submits progress and notes for assigned task | Task summary changes and history entry is stored |
| T12 | Progress | Submit percentage above 100 or below 0 | Update is rejected |
| T13 | Alerts | Create an unfinished task with past due date | Task appears in overdue alerts |
| T14 | Alerts | Complete the overdue task | It no longer appears in active deadline alerts |
| T15 | Resources | Create resource and allocate within quantity | Allocation is saved and allocated total changes |
| T16 | Resources | Allocate more than available quantity | Allocation is rejected |
| T17 | Reports | Export task report as CSV | A CSV file downloads with the selected task rows |
| T18 | Security | Submit a POST form without a valid CSRF token | Request is rejected |
