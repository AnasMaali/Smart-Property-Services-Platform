# System Architecture — Version 1

This document presents two architecture diagrams for Version 1 of the Smart Property Services Platform.

The first diagram is the **comprehensive architecture diagram**, which shows the internal Backend modules and detailed data flows.

The second diagram is the **simplified architecture diagram**, which shows only the main system components and their connections.

---

## 1. Comprehensive System Architecture

This is the more detailed architecture diagram. It shows the main system interfaces, Backend modules, external services, shared database, monitoring, and backup components.

```mermaid
---
config:
  layout: elk
---
flowchart LR

    subgraph INTERFACES["🖥️ System Interfaces"]
        MOBILE(["📱 Customer Mobile App<br/>iOS & Android"])
        ADMIN(["🧑‍💼 Admin Management Interface"])
        WEBSITE(["🌐 Company Portfolio Website"])
    end

    subgraph BACKEND["⚙️ Backend System"]
        API{{"🔌 Backend API"}}

        subgraph MODULES["Version 1 Backend Modules"]
            AUTH["🔐 Authentication<br/>& Access Control"]
            CUSTOMER["👤 Customer & Profile<br/>Management"]
            SERVICES["🛠️ Services, Categories<br/>& Pricing"]
            CART["🛒 Cart & Checkout<br/>Management"]
            BOOKING["📅 Booking, Property<br/>& Appointment"]
            PAYMENT["💳 Payment & Receipt<br/>Management"]
            TECHNICIAN["👷 Technician Records<br/>& Assignment"]
            SUPPORT["🎧 Human Support<br/>Management"]
            RATING["⭐ Rating & Feedback<br/>Management"]
            AUDIT["📋 Admin Audit<br/>& Operations"]
        end
    end

    subgraph INFRA["💾 Data & Operations"]
        DATABASE[("🗄️ Shared Operational Database")]
        MONITORING["📊 Logs & Monitoring"]
        BACKUP[("♻️ Backup & Recovery")]
    end

    subgraph EXTERNAL["🔗 External Services"]
        PAYMENT_GATEWAY{{"🏦 Payment Gateway"}}
        OTP_GATEWAY{{"📨 OTP / SMS Gateway"}}
    end

    MOBILE -->|"HTTPS requests<br/>Login, services, cart, booking"| API
    API -->|"Customer data<br/>services, statuses, receipts"| MOBILE

    ADMIN -->|"HTTPS management requests"| API
    API -->|"Bookings, customers,<br/>technicians, reports"| ADMIN

    WEBSITE -->|"Request public company<br/>and service data"| API
    API -->|"Approved public data"| WEBSITE

    API --> AUTH
    API --> CUSTOMER
    API --> SERVICES
    API --> CART
    API --> BOOKING
    API --> PAYMENT
    API --> TECHNICIAN
    API --> SUPPORT
    API --> RATING
    API --> AUDIT

    AUTH <-->|"Account & session data"| DATABASE
    CUSTOMER <-->|"Customer profile data"| DATABASE
    SERVICES <-->|"Services, categories & prices"| DATABASE
    CART <-->|"Cart & cart item data"| DATABASE
    BOOKING <-->|"Booking & appointment data"| DATABASE
    PAYMENT <-->|"Payment & receipt records"| DATABASE
    TECHNICIAN <-->|"Technician & assignment data"| DATABASE
    SUPPORT <-->|"Support request data"| DATABASE
    RATING <-->|"Ratings & comments"| DATABASE
    AUDIT -->|"Admin action records"| DATABASE

    AUTH -->|"Send OTP request"| OTP_GATEWAY
    OTP_GATEWAY -->|"Delivery / verification result"| AUTH

    PAYMENT -->|"Payment request<br/>amount & reference"| PAYMENT_GATEWAY
    PAYMENT_GATEWAY -->|"Verified payment result<br/>status & reference"| PAYMENT

    API -->|"Errors & request activity"| MONITORING
    AUTH -->|"Login & OTP events"| MONITORING
    PAYMENT -->|"Payment events"| MONITORING
    AUDIT -->|"Admin activity"| MONITORING

    DATABASE -->|"Scheduled backup data"| BACKUP
    BACKUP -.->|"Authorized restore"| DATABASE

    classDef interface fill:#EAF2FF,stroke:#2563EB,stroke-width:2px;
    classDef backend fill:#EEFDF4,stroke:#16A34A,stroke-width:2px;
    classDef data fill:#FFF7E6,stroke:#D97706,stroke-width:2px;
    classDef external fill:#F5EEFF,stroke:#7C3AED,stroke-width:2px;

    class MOBILE,ADMIN,WEBSITE interface;
    class API,AUTH,CUSTOMER,SERVICES,CART,BOOKING,PAYMENT,TECHNICIAN,SUPPORT,RATING,AUDIT backend;
    class DATABASE,MONITORING,BACKUP data;
    class PAYMENT_GATEWAY,OTP_GATEWAY external;
```

---

## 2. Simplified System Architecture

This is the simpler architecture diagram. It shows only the main system interfaces, Backend API, shared database, external services, monitoring, and backup components.

```mermaid
---
config:
  layout: elk
---
flowchart LR

    MOBILE["📱 Customer Mobile App<br/>iOS & Android"]
    ADMIN["🧑‍💼 Admin Interface"]
    WEBSITE["🌐 Portfolio Website"]

    API["⚙️ Backend API<br/>Authentication, Validation & Business Logic"]

    DATABASE[("🗄️ Shared Database")]

    PAYMENT["💳 Payment Gateway"]
    OTP["📩 OTP / SMS Gateway"]

    LOGS["📊 Logs & Monitoring"]
    BACKUP[("♻️ Database Backup")]

    MOBILE <-->|"Customer requests and data"| API
    ADMIN <-->|"Management requests and data"| API
    WEBSITE <-->|"Public service data"| API

    API <-->|"Read and store system data"| DATABASE

    API -->|"Payment request"| PAYMENT
    PAYMENT -->|"Verified payment result"| API

    API -->|"OTP request"| OTP
    OTP -->|"Delivery and verification result"| API

    API -->|"System activity and errors"| LOGS
    DATABASE -->|"Scheduled backup"| BACKUP

    classDef interface fill:#EAF2FF,stroke:#2563EB,stroke-width:2px;
    classDef backend fill:#ECFDF3,stroke:#16A34A,stroke-width:2px;
    classDef database fill:#FFF7E6,stroke:#D97706,stroke-width:2px;
    classDef external fill:#F5EEFF,stroke:#7C3AED,stroke-width:2px;

    class MOBILE,ADMIN,WEBSITE interface;
    class API backend;
    class DATABASE,LOGS,BACKUP database;
    class PAYMENT,OTP external;
```
