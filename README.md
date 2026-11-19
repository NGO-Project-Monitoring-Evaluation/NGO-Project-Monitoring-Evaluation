
# NGO Project Monitoring and Evaluation System

A web-based platform designed to help Non-Governmental Organizations (NGOs) manage projects, monitor activities, track beneficiaries, measure performance indicators, manage finances, and generate reports from a centralized system.

## Overview

The NGO Project Monitoring and Evaluation System is a web-based application developed to improve how NGOs plan, implement, monitor, evaluate and report on development projects. The system brings programme operations and financial management together, enabling organizations to monitor project implementation alongside budgets, donor funds, expenditures and commitments. It provides role-based access to ensure that users can access the information and functions relevant to their responsibilities.

## Key Features
### 1. Project Management
- Create and manage development projects.
- Record project descriptions, locations, timelines and responsible officers.
- Monitor project implementation progress.
- View project details and implementation status.

### 2. Activity Management
- Create and assign project activities.
- Set planned start and completion dates.
- Track activity progress and status.
- Identify delayed activities.
- Record reasons for implementation delays.

### 3. Beneficiary Management
- Register and manage beneficiaries.
- Generate unique beneficiary identification codes.
- Prevent duplicate beneficiary records.
- Record beneficiary consent.
- Enroll beneficiaries in relevant projects.

### 4. Indicator Monitoring and Evaluation
- Define project performance indicators.
- Set baselines, targets and reporting periods.
- Record actual indicator values.
- Automatically calculate performance progress.
- Monitor progress against project targets.

### 5. Financial Management
- Manage project budgets and budget lines.
- Register donors and grants.
- Track funds received.
- Record project expenditures.
- Manage purchase orders and financial commitments.
- Monitor available balances and budget utilization.
- Generate budget-versus-actual reports.
- Identify unusual expenditure patterns through financial alerts.

### 6. Reports and Analytics
- Generate activity reports.
- Generate indicator monitoring reports.
- Generate beneficiary reports.
- Generate budget-versus-actual reports.
- Generate cost variance analysis.
- Generate commitments reports.
- Generate donor funds reports.
- Export reports to Excel and PDF.

### 7. Client Project Tracking
- Generate unique tracking codes for projects.
- Allow authorized staff to access project tracking information.
- Provide an external tracking portal where clients can view published project progress information using their tracking codes.

### 8. User and Access Management
The system supports seven user roles:
| Role | Responsibility |
|---|---|
| Super Administrator | Overall system administration and transaction approvals |
| Finance Officer | Financial records, budgets and financial reports |
| Project Manager | Project and activity management |
| M&E Officer | Indicator monitoring and evaluation |
| Field Officer | Field activity updates and beneficiary records |
| Auditor | Read-only access to authorized records and audit logs |
| Donor/Partner | Access to approved information for assigned projects |

### 9. Audit and Accountability
- Record user login activities.
- Track record creation, updates and deletions.
- Maintain transaction approval records.
- Support accountability and administrative oversight.

## Technology Stack
The application is built using the following technologies:

| Technology | Purpose |
|---|---|
| PHP | Server-side application development |
| MySQL | Relational database management |
| HTML5 | Web page structure |
| CSS3 | Interface styling |
| JavaScript | Client-side interaction |
| Bootstrap | Responsive user interface |
| Git and GitHub | Version control and collaboration |

## System Architecture

The application follows a three-tier client-server architecture:
1. **Presentation Layer:** The web interface accessed through a browser.
2. **Application Layer:** PHP handles authentication, business logic, validation and access control.
3. **Database Layer:** MySQL stores project, beneficiary, financial, user and monitoring information.





