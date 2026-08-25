-- =============================================================
-- BLUE — Smart Property Services Platform
-- Version 1 Database Seed Data
-- Database: target database selected by the mysql client
-- MySQL Version: 8.0+
-- Character Set: utf8mb4
--
-- This file contains reference and initial system data only.
-- It does not contain customers, users, bookings, payments,
-- technicians, services, prices, or production data.
-- =============================================================


SET NAMES utf8mb4
COLLATE utf8mb4_0900_ai_ci;

START TRANSACTION;


-- =============================================================
-- 1. USER ACCOUNT STATUSES
-- =============================================================

INSERT INTO user_account_statuses (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'PENDING_VERIFICATION',
    'Pending Verification',
    'The account was created but the phone number has not been verified yet.',
    1,
    TRUE
),
(
    'ACTIVE',
    'Active',
    'The account is verified and allowed to access the platform.',
    2,
    TRUE
),
(
    'SUSPENDED',
    'Suspended',
    'The account is temporarily prevented from accessing protected platform features.',
    3,
    TRUE
),
(
    'DEACTIVATED',
    'Deactivated',
    'The account has been deactivated and cannot access the platform.',
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 2. SYSTEM ROLES
-- =============================================================

INSERT INTO roles (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'CUSTOMER',
    'Customer',
    'A registered customer who can browse, book, pay for, and track property services.',
    TRUE
),
(
    'ADMIN',
    'Admin',
    'An authorized company employee who manages operational platform data.',
    TRUE
),
(
    'SUPER_ADMIN',
    'Super Admin',
    'The company owner or highest-authority administrator responsible for system administration.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 2A. ADMIN CAPABILITIES (PERMISSIONS) — BLUE V1 Phase A1
--
-- Stable capability codes, `family.action` naming convention. Each code
-- here MUST have a matching case in App\Support\Admin\AdminCapability.
-- Only capabilities enforced by an already-shipped /v1/admin/* route are
-- seeded here — a future Admin module adds its own row(s) in lockstep with
-- the route(s) that need them, never speculatively ahead of that route.
-- =============================================================

INSERT INTO admin_permissions (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'bookings.view',
    'View Bookings',
    'View paid Bookings and Booking Items across every customer, including payment summary, technician assignment history, and cancellation/refund snapshot.',
    TRUE
),
(
    'technicians.view',
    'View Technicians',
    'View Technician records, availability, specializations, and the server-computed eligible-candidate list for a Booking Item.',
    TRUE
),
(
    'technicians.assign',
    'Assign Technicians',
    'Assign, reassign, start, and complete Technician work on a Booking Item.',
    TRUE
),
(
    'contracts.view',
    'View Service Contracts',
    'View Service Contracts and their items, status history, and acceptance/billing summary.',
    TRUE
),
(
    'contracts.manage',
    'Manage Service Contracts',
    'Approve, send for customer acceptance, and suspend a Service Contract.',
    TRUE
),
(
    'contracts.cancel',
    'Cancel Service Contracts',
    'Cancel a Service Contract, permanently stopping it from authorizing further Contract Bookings.',
    TRUE
),
(
    'payments.view',
    'View Payments',
    'View one-off Payment Attempts across every customer - status, amount, provider reference, linked Booking, and recent webhook processing history. Read-only: no refund, retry, or status-override capability exists.',
    TRUE
),
(
    'billing.view',
    'View Contract Billing',
    'View recurring Service Contract Billing state across every customer - subscription status, billing period, past-due/cancellation state, and recent webhook processing history. Read-only.',
    TRUE
),
(
    'customers.view',
    'View Customers',
    'View Customer profiles and their Properties across the platform - contact info, account status, location/relationship profile, account-deletion state, and small operational activity counts. Read-only: no account/property mutation capability exists.',
    TRUE
),
(
    'support.view',
    'View Support Requests',
    'View Support Requests across every customer, including linked Booking, assignment, and the full message conversation.',
    TRUE
),
(
    'support.manage',
    'Manage Support Requests',
    'Post an Admin reply message on a Support Request. Status-transition and assignment mutations are not yet implemented.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 2B. ADMIN ROLE -> CAPABILITY GRANTS — BLUE V1 Phase A1
--
-- ADMIN receives every capability an existing Admin Operations route
-- enforces today, preserving BLUE V1 Phase 9B's current behavior exactly
-- (ADMIN and SUPER_ADMIN had identical operational access before this
-- phase). SUPER_ADMIN needs no rows here at all: it is authorized for
-- every capability - present and future - through the centralized,
-- explicit override in App\Support\Admin\AdminAuthorizationService, never
-- through a row in this table. Re-running this block is a no-op (the
-- ON DUPLICATE KEY UPDATE clause only ever refreshes granted_at on an
-- already-existing grant).
-- =============================================================

INSERT INTO admin_role_permissions (
    role_id,
    permission_id,
    granted_by_user_id,
    granted_at
)
SELECT
    r.id,
    p.id,
    NULL,
    CURRENT_TIMESTAMP(6)
FROM roles r
CROSS JOIN admin_permissions p
WHERE r.code = 'ADMIN'
  AND p.code IN (
    'bookings.view',
    'technicians.view',
    'technicians.assign',
    'contracts.view',
    'contracts.manage',
    'contracts.cancel',
    'payments.view',
    'billing.view',
    'customers.view',
    'support.view',
    'support.manage'
  )
ON DUPLICATE KEY UPDATE
    granted_at = granted_at;


-- =============================================================
-- 2C. ADMIN WEBAUTHN CHALLENGE PURPOSES — BLUE V1 Phase A2.1
--
-- Reference codes for admin_webauthn_challenges.purpose_id, mirroring the
-- otp_verification_purposes convention. Schema foundation only - no
-- registration/login/step-up ceremony logic exists yet (Phase A2.2+).
-- =============================================================

INSERT INTO admin_webauthn_challenge_purposes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'REGISTRATION',
    'Registration',
    'A challenge issued while an Admin registers a new WebAuthn credential.',
    TRUE
),
(
    'LOGIN_ASSERTION',
    'Login Assertion',
    'A challenge issued during Admin login, to be proven by an existing WebAuthn credential.',
    TRUE
),
(
    'STEP_UP',
    'Step-Up',
    'A challenge issued to re-verify an already-authenticated Admin before a sensitive operation.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 3. PROPERTY RELATIONSHIP TYPES
-- =============================================================

INSERT INTO property_relationship_types (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'PROPERTY_OWNER',
    'Property Owner',
    'The customer owns the property.',
    1,
    TRUE
),
(
    'TENANT',
    'Tenant',
    'The customer rents or occupies the property as a tenant.',
    2,
    TRUE
),
(
    'PROPERTY_MANAGER',
    'Property Manager',
    'The customer manages the property on behalf of its owner.',
    3,
    TRUE
),
(
    'COMPANY_OFFICE_REPRESENTATIVE',
    'Company / Office Representative',
    'The customer represents a company, office, or business property.',
    4,
    TRUE
),
(
    'OTHER',
    'Other',
    'The customer has another relationship to the property.',
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 4. OTP VERIFICATION PURPOSES
-- =============================================================

INSERT INTO otp_verification_purposes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'PHONE_VERIFICATION',
    'Phone Verification',
    'Used to verify the customer phone number during account registration.',
    TRUE
),
(
    'PASSWORD_RESET',
    'Password Reset',
    'Used to verify identity before resetting the account password.',
    TRUE
),
(
    'PHONE_NUMBER_CHANGE',
    'Phone Number Change',
    'Used to verify a new phone number before updating the account.',
    TRUE
),
(
    'LOGIN',
    'Login',
    'Used to authenticate a customer via passwordless phone + OTP login.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 5. OTP VERIFICATION STATUSES
-- =============================================================

INSERT INTO otp_verification_statuses (
    code,
    name,
    is_terminal
)
VALUES
(
    'PENDING',
    'Pending',
    FALSE
),
(
    'VERIFIED',
    'Verified',
    TRUE
),
(
    'EXPIRED',
    'Expired',
    TRUE
),
(
    'INVALIDATED',
    'Invalidated',
    TRUE
),
(
    'ATTEMPTS_EXCEEDED',
    'Attempts Exceeded',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    is_terminal = new.is_terminal;


-- =============================================================
-- 6. AUTHENTICATION CLIENT TYPES
-- =============================================================

INSERT INTO auth_client_types (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'MOBILE_IOS',
    'iOS Mobile Application',
    'Authentication session created through the BLUE iOS mobile application.',
    TRUE
),
(
    'MOBILE_ANDROID',
    'Android Mobile Application',
    'Authentication session created through the BLUE Android mobile application.',
    TRUE
),
(
    'ADMIN_WEB',
    'Admin Web Dashboard',
    'Authentication session created through the web-based Admin Dashboard.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 7. COUNTRIES
-- Version 1 operates in the United Arab Emirates
-- =============================================================

INSERT INTO countries (
    iso2_code,
    iso3_code,
    name,
    display_order,
    is_active
)
VALUES
(
    'AE',
    'ARE',
    'United Arab Emirates',
    1,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    iso3_code = new.iso3_code,
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


SET @uae_country_id = (
    SELECT id
    FROM countries
    WHERE iso2_code = 'AE'
    LIMIT 1
);


-- =============================================================
-- 8. UAE CITIES
-- =============================================================

INSERT INTO cities (
    country_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @uae_country_id,
    'ABU_DHABI',
    'Abu Dhabi',
    1,
    TRUE
),
(
    @uae_country_id,
    'DUBAI',
    'Dubai',
    2,
    TRUE
),
(
    @uae_country_id,
    'SHARJAH',
    'Sharjah',
    3,
    TRUE
),
(
    @uae_country_id,
    'AJMAN',
    'Ajman',
    4,
    TRUE
),
(
    @uae_country_id,
    'UMM_AL_QUWAIN',
    'Umm Al Quwain',
    5,
    TRUE
),
(
    @uae_country_id,
    'RAS_AL_KHAIMAH',
    'Ras Al Khaimah',
    6,
    TRUE
),
(
    @uae_country_id,
    'FUJAIRAH',
    'Fujairah',
    7,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- GET UAE CITY IDENTIFIERS
-- =============================================================

SET @abu_dhabi_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'ABU_DHABI'
    LIMIT 1
);

SET @dubai_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'DUBAI'
    LIMIT 1
);

SET @sharjah_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'SHARJAH'
    LIMIT 1
);

SET @ajman_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'AJMAN'
    LIMIT 1
);

SET @umm_al_quwain_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'UMM_AL_QUWAIN'
    LIMIT 1
);

SET @ras_al_khaimah_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'RAS_AL_KHAIMAH'
    LIMIT 1
);

SET @fujairah_city_id = (
    SELECT id
    FROM cities
    WHERE country_id = @uae_country_id
      AND code = 'FUJAIRAH'
    LIMIT 1
);


-- =============================================================
-- 9. ABU DHABI AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @abu_dhabi_city_id,
    'AL_KHALIDIYAH',
    'Al Khalidiyah',
    1,
    TRUE
),
(
    @abu_dhabi_city_id,
    'AL_REEM_ISLAND',
    'Al Reem Island',
    2,
    TRUE
),
(
    @abu_dhabi_city_id,
    'AL_MUSHRIF',
    'Al Mushrif',
    3,
    TRUE
),
(
    @abu_dhabi_city_id,
    'KHALIFA_CITY',
    'Khalifa City',
    4,
    TRUE
),
(
    @abu_dhabi_city_id,
    'MOHAMMED_BIN_ZAYED_CITY',
    'Mohammed Bin Zayed City',
    5,
    TRUE
),
(
    @abu_dhabi_city_id,
    'AL_ZAHIYAH',
    'Al Zahiyah',
    6,
    TRUE
),
(
    @abu_dhabi_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 10. DUBAI AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @dubai_city_id,
    'DOWNTOWN_DUBAI',
    'Downtown Dubai',
    1,
    TRUE
),
(
    @dubai_city_id,
    'DUBAI_MARINA',
    'Dubai Marina',
    2,
    TRUE
),
(
    @dubai_city_id,
    'BUSINESS_BAY',
    'Business Bay',
    3,
    TRUE
),
(
    @dubai_city_id,
    'JUMEIRAH',
    'Jumeirah',
    4,
    TRUE
),
(
    @dubai_city_id,
    'AL_BARSHA',
    'Al Barsha',
    5,
    TRUE
),
(
    @dubai_city_id,
    'DEIRA',
    'Deira',
    6,
    TRUE
),
(
    @dubai_city_id,
    'BUR_DUBAI',
    'Bur Dubai',
    7,
    TRUE
),
(
    @dubai_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 11. SHARJAH AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @sharjah_city_id,
    'AL_MAJAZ',
    'Al Majaz',
    1,
    TRUE
),
(
    @sharjah_city_id,
    'AL_NAHDA',
    'Al Nahda',
    2,
    TRUE
),
(
    @sharjah_city_id,
    'AL_KHAN',
    'Al Khan',
    3,
    TRUE
),
(
    @sharjah_city_id,
    'AL_TAAWUN',
    'Al Taawun',
    4,
    TRUE
),
(
    @sharjah_city_id,
    'MUWAILEH_COMMERCIAL',
    'Muwaileh Commercial',
    5,
    TRUE
),
(
    @sharjah_city_id,
    'AL_QASIMIA',
    'Al Qasimia',
    6,
    TRUE
),
(
    @sharjah_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 12. AJMAN AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @ajman_city_id,
    'AL_NUAIMIYA',
    'Al Nuaimiya',
    1,
    TRUE
),
(
    @ajman_city_id,
    'AL_RASHIDIYA',
    'Al Rashidiya',
    2,
    TRUE
),
(
    @ajman_city_id,
    'AL_JURF',
    'Al Jurf',
    3,
    TRUE
),
(
    @ajman_city_id,
    'AL_RAWDA',
    'Al Rawda',
    4,
    TRUE
),
(
    @ajman_city_id,
    'AL_MOWAIHAT',
    'Al Mowaihat',
    5,
    TRUE
),
(
    @ajman_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 13. UMM AL QUWAIN AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @umm_al_quwain_city_id,
    'AL_SALAMAH',
    'Al Salamah',
    1,
    TRUE
),
(
    @umm_al_quwain_city_id,
    'AL_RASS',
    'Al Rass',
    2,
    TRUE
),
(
    @umm_al_quwain_city_id,
    'AL_HUMRAH',
    'Al Humrah',
    3,
    TRUE
),
(
    @umm_al_quwain_city_id,
    'AL_RAUDAH',
    'Al Raudah',
    4,
    TRUE
),
(
    @umm_al_quwain_city_id,
    'AL_RIQQAH',
    'Al Riqqah',
    5,
    TRUE
),
(
    @umm_al_quwain_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 14. RAS AL KHAIMAH AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @ras_al_khaimah_city_id,
    'AL_NAKHEEL',
    'Al Nakheel',
    1,
    TRUE
),
(
    @ras_al_khaimah_city_id,
    'AL_DHAIT',
    'Al Dhait',
    2,
    TRUE
),
(
    @ras_al_khaimah_city_id,
    'AL_HAMRA_VILLAGE',
    'Al Hamra Village',
    3,
    TRUE
),
(
    @ras_al_khaimah_city_id,
    'KHUZAM',
    'Khuzam',
    4,
    TRUE
),
(
    @ras_al_khaimah_city_id,
    'MINA_AL_ARAB',
    'Mina Al Arab',
    5,
    TRUE
),
(
    @ras_al_khaimah_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 15. FUJAIRAH AREAS
-- =============================================================

INSERT INTO areas (
    city_id,
    code,
    name,
    display_order,
    is_active
)
VALUES
(
    @fujairah_city_id,
    'AL_FASEEL',
    'Al Faseel',
    1,
    TRUE
),
(
    @fujairah_city_id,
    'AL_GURFA',
    'Al Gurfa',
    2,
    TRUE
),
(
    @fujairah_city_id,
    'MADHAB',
    'Madhab',
    3,
    TRUE
),
(
    @fujairah_city_id,
    'SAKAMKAM',
    'Sakamkam',
    4,
    TRUE
),
(
    @fujairah_city_id,
    'AL_HAYL',
    'Al Hayl',
    5,
    TRUE
),
(
    @fujairah_city_id,
    'OTHER',
    'Other',
    99,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 16. CURRENCIES
-- Version 1 supports AED only
-- =============================================================

INSERT INTO currencies (
    code,
    numeric_code,
    name,
    symbol,
    minor_unit,
    is_active
)
VALUES
(
    'AED',
    '784',
    'United Arab Emirates Dirham',
    'د.إ',
    2,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    numeric_code = new.numeric_code,
    name = new.name,
    symbol = new.symbol,
    minor_unit = new.minor_unit,
    is_active = new.is_active;


-- =============================================================
-- 17. SERVICE OPTION TYPES
-- =============================================================

INSERT INTO service_option_types (
    code,
    name,
    description,
    has_predefined_choices,
    allows_multiple_values,
    is_active
)
VALUES
(
    'TEXT',
    'Text',
    'Allows the customer to enter a text value or additional instructions.',
    FALSE,
    FALSE,
    TRUE
),
(
    'NUMBER',
    'Number',
    'Allows the customer to enter or select a numeric value.',
    FALSE,
    FALSE,
    TRUE
),
(
    'BOOLEAN',
    'Yes / No',
    'Allows the customer to select a Yes or No value.',
    FALSE,
    FALSE,
    TRUE
),
(
    'SINGLE_SELECT',
    'Single Selection',
    'Allows the customer to select one value from predefined choices.',
    TRUE,
    FALSE,
    TRUE
),
(
    'MULTI_SELECT',
    'Multiple Selection',
    'Allows the customer to select multiple values from predefined choices.',
    TRUE,
    TRUE,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    has_predefined_choices = new.has_predefined_choices,
    allows_multiple_values = new.allows_multiple_values,
    is_active = new.is_active;


-- =============================================================
-- 18. MEASUREMENT UNITS
-- =============================================================

INSERT INTO measurement_units (
    code,
    name,
    symbol,
    display_order,
    is_active
)
VALUES
(
    'ROOM',
    'Room',
    'room',
    1,
    TRUE
),
(
    'UNIT',
    'Unit',
    'unit',
    2,
    TRUE
),
(
    'SQUARE_METER',
    'Square Meter',
    'm²',
    3,
    TRUE
),
(
    'HOUR',
    'Hour',
    'hr',
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    symbol = new.symbol,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 19. PROPERTY TYPES
-- =============================================================

INSERT INTO property_types (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'APARTMENT',
    'Apartment',
    'A residential apartment located inside a building.',
    1,
    TRUE
),
(
    'HOUSE',
    'House',
    'An independent residential house.',
    2,
    TRUE
),
(
    'VILLA',
    'Villa',
    'A standalone or semi-detached residential villa.',
    3,
    TRUE
),
(
    'BUILDING',
    'Building',
    'A residential, commercial, or mixed-use building.',
    4,
    TRUE
),
(
    'OFFICE',
    'Office',
    'A commercial office or workplace.',
    5,
    TRUE
),
(
    'OTHER',
    'Other',
    'Another property type not included in the available list.',
    6,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 20. CART STATUSES
-- =============================================================

INSERT INTO cart_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'ACTIVE',
    'Active',
    'The customer is currently adding or editing Services in the Cart.',
    FALSE,
    1,
    TRUE
),
(
    'CHECKOUT',
    'Checkout',
    'The Cart has entered the checkout and Payment preparation process.',
    FALSE,
    2,
    TRUE
),
(
    'CONVERTED',
    'Converted',
    'The Cart was successfully converted into a Booking after confirmed Payment.',
    TRUE,
    3,
    TRUE
),
(
    'ABANDONED',
    'Abandoned',
    'The Cart was left without completing checkout.',
    TRUE,
    4,
    TRUE
),
(
    'EXPIRED',
    'Expired',
    'The Cart expired according to the platform retention rules.',
    TRUE,
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 21. BOOKING STATUSES
-- Status of the complete Booking
-- =============================================================

INSERT INTO booking_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'PAID',
    'Paid',
    'Payment was confirmed and the Booking is ready for operational processing.',
    FALSE,
    1,
    TRUE
),
(
    'ASSIGNED',
    'Assigned',
    'The required Booking Items have been assigned to Technicians.',
    FALSE,
    2,
    TRUE
),
(
    'IN_PROGRESS',
    'In Progress',
    'One or more Services in the Booking are currently being performed.',
    FALSE,
    3,
    TRUE
),
(
    'COMPLETED',
    'Completed',
    'All required Services in the Booking have been completed.',
    TRUE,
    4,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The Booking was cancelled by an authorized Admin.',
    TRUE,
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 22. BOOKING ITEM STATUSES
-- Status of each Service inside the Booking
-- =============================================================

INSERT INTO booking_item_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'PENDING_ASSIGNMENT',
    'Pending Assignment',
    'The Booking Item is waiting for a suitable Technician assignment.',
    FALSE,
    1,
    TRUE
),
(
    'ASSIGNED',
    'Assigned',
    'A Technician has been assigned to the Booking Item.',
    FALSE,
    2,
    TRUE
),
(
    'IN_PROGRESS',
    'In Progress',
    'The Technician has started performing the Service.',
    FALSE,
    3,
    TRUE
),
(
    'COMPLETED',
    'Completed',
    'The Service represented by the Booking Item has been completed.',
    TRUE,
    4,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The Booking Item was cancelled by an authorized Admin.',
    TRUE,
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 23. PAYMENT STATUSES
-- =============================================================

INSERT INTO payment_statuses (
    code,
    name,
    description,
    is_final_for_checkout,
    allows_booking_creation,
    display_order,
    is_active
)
VALUES
(
    'PENDING',
    'Pending',
    'The system is waiting for a final result from the Payment Gateway.',
    FALSE,
    FALSE,
    1,
    TRUE
),
(
    'SUCCESSFUL',
    'Successful',
    'The Payment Gateway confirmed that the Payment was completed successfully.',
    TRUE,
    TRUE,
    2,
    TRUE
),
(
    'FAILED',
    'Failed',
    'The Payment Attempt ended without a successful Payment.',
    TRUE,
    FALSE,
    3,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The Payment Attempt was cancelled before successful completion.',
    TRUE,
    FALSE,
    4,
    TRUE
),
(
    'REFUNDED',
    'Refunded',
    'The successfully paid amount was later refunded through an approved process.',
    TRUE,
    FALSE,
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_final_for_checkout = new.is_final_for_checkout,
    allows_booking_creation = new.allows_booking_creation,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 23A. PAYMENT WEBHOOK EVENT STATUSES
-- Technical processing lifecycle of one inbound payment provider
-- webhook delivery (payment_webhook_events.status_id). Distinct
-- from payment_statuses, which is the customer-facing Payment
-- business status.
-- =============================================================

INSERT INTO payment_webhook_event_statuses (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'RECEIVED',
    'Received',
    'The webhook delivery was authenticated and ledgered but not yet processed.',
    1,
    TRUE
),
(
    'PROCESSED',
    'Processed',
    'The webhook delivery was successfully applied to a Payment Attempt state transition.',
    2,
    TRUE
),
(
    'IGNORED',
    'Ignored',
    'The webhook delivery was authentic but did not require or trigger any Payment Attempt state change.',
    3,
    TRUE
),
(
    'FAILED',
    'Failed',
    'The webhook delivery could not be processed and may be retried by the provider.',
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 24. TECHNICIAN STATUSES
-- =============================================================

INSERT INTO technician_statuses (
    code,
    name,
    description,
    is_assignable,
    display_order,
    is_active
)
VALUES
(
    'AVAILABLE',
    'Available',
    'The Technician is active and available for new Booking Item assignments.',
    TRUE,
    1,
    TRUE
),
(
    'BUSY',
    'Busy',
    'The Technician is currently occupied and should not receive new assignments.',
    FALSE,
    2,
    TRUE
),
(
    'ON_LEAVE',
    'On Leave',
    'The Technician is temporarily unavailable because of approved leave.',
    FALSE,
    3,
    TRUE
),
(
    'INACTIVE',
    'Inactive',
    'The Technician is not currently available for operational assignments.',
    FALSE,
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_assignable = new.is_assignable,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 25. SUPPORT REQUEST STATUSES
-- =============================================================

INSERT INTO support_request_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'OPEN',
    'Open',
    'The Support Request was submitted and is waiting for review.',
    FALSE,
    1,
    TRUE
),
(
    'IN_PROGRESS',
    'In Progress',
    'An authorized Admin is currently reviewing or handling the Support Request.',
    FALSE,
    2,
    TRUE
),
(
    'RESOLVED',
    'Resolved',
    'A solution or final response was provided for the Support Request.',
    TRUE,
    3,
    TRUE
),
(
    'CLOSED',
    'Closed',
    'The Support Request was formally closed and requires no further action.',
    TRUE,
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 26. SERVICE CATEGORIES
-- =============================================================

INSERT INTO service_categories (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'AC',
    'AC',
    'Air-conditioning cleaning, repair, installation, and maintenance services.',
    1,
    TRUE
),
(
    'CLEANING',
    'Cleaning',
    'Residential and commercial property cleaning services.',
    2,
    TRUE
),
(
    'PLUMBING',
    'Plumbing',
    'Plumbing installation, maintenance, and repair services.',
    3,
    TRUE
),
(
    'ELECTRICAL',
    'Electrical',
    'Electrical installation, maintenance, and repair services.',
    4,
    TRUE
),
(
    'PAINTING',
    'Painting',
    'Interior and exterior property painting services.',
    5,
    TRUE
),
(
    'HANDYMAN',
    'Handyman',
    'General installation, assembly, and minor property repair services.',
    6,
    TRUE
),
(
    'PEST_CONTROL',
    'Pest Control',
    'Property pest inspection, treatment, and prevention services.',
    7,
    TRUE
),
(
    'MASONRY',
    'Masonry',
    'Block, brick, concrete, and general masonry services.',
    8,
    TRUE
),
(
    'GYPSUM',
    'Gypsum',
    'Gypsum-board installation, repair, and finishing services.',
    9,
    TRUE
),
(
    'WATERPROOFING',
    'Waterproofing',
    'Property waterproofing and water-leak protection services.',
    10,
    TRUE
),
(
    'FLOORING',
    'Flooring',
    'Floor installation, replacement, and repair services.',
    11,
    TRUE
),
(
    'TILING',
    'Tiling',
    'Wall and floor tile installation and repair services.',
    12,
    TRUE
),
(
    'CARPENTRY',
    'Carpentry',
    'Woodwork, furniture, door, cabinet, and carpentry services.',
    13,
    TRUE
),
(
    'LANDSCAPING',
    'Landscaping',
    'Garden, outdoor-area, and landscaping services.',
    14,
    TRUE
),
(
    'SWIMMING_POOL',
    'Swimming Pool',
    'Swimming-pool cleaning, maintenance, and repair services.',
    15,
    TRUE
),
(
    'SMART_HOME',
    'Smart Home',
    'Smart-home device installation, setup, and maintenance services.',
    16,
    TRUE
),
(
    'CCTV',
    'CCTV',
    'Security-camera installation, configuration, and maintenance services.',
    17,
    TRUE
),
(
    'OTHER',
    'Other',
    'Other approved property services not included in the available categories.',
    18,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 27. TECHNICIAN SPECIALIZATIONS
-- =============================================================

INSERT INTO specializations (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'AC_TECHNICIAN',
    'AC Technician',
    'Technician specialized in air-conditioning cleaning, repair, installation, and maintenance.',
    1,
    TRUE
),
(
    'CLEANING_SPECIALIST',
    'Cleaning Specialist',
    'Service employee specialized in residential and commercial property cleaning.',
    2,
    TRUE
),
(
    'PLUMBER',
    'Plumber',
    'Technician specialized in plumbing installation, maintenance, and repair.',
    3,
    TRUE
),
(
    'ELECTRICIAN',
    'Electrician',
    'Technician specialized in electrical installation, maintenance, and repair.',
    4,
    TRUE
),
(
    'PAINTER',
    'Painter',
    'Technician specialized in interior and exterior property painting.',
    5,
    TRUE
),
(
    'HANDYMAN',
    'Handyman',
    'Technician specialized in general installation, assembly, and minor property repairs.',
    6,
    TRUE
),
(
    'PEST_CONTROL_TECHNICIAN',
    'Pest Control Technician',
    'Technician specialized in pest inspection, treatment, and prevention.',
    7,
    TRUE
),
(
    'MASON',
    'Mason',
    'Technician specialized in block, brick, concrete, and general masonry work.',
    8,
    TRUE
),
(
    'GYPSUM_TECHNICIAN',
    'Gypsum Technician',
    'Technician specialized in gypsum-board installation, repair, and finishing.',
    9,
    TRUE
),
(
    'WATERPROOFING_SPECIALIST',
    'Waterproofing Specialist',
    'Technician specialized in waterproofing and protection against water leakage.',
    10,
    TRUE
),
(
    'FLOORING_TECHNICIAN',
    'Flooring Technician',
    'Technician specialized in flooring installation, replacement, and repair.',
    11,
    TRUE
),
(
    'TILING_TECHNICIAN',
    'Tiling Technician',
    'Technician specialized in wall and floor tile installation and repair.',
    12,
    TRUE
),
(
    'CARPENTER',
    'Carpenter',
    'Technician specialized in woodwork, furniture, doors, cabinets, and carpentry.',
    13,
    TRUE
),
(
    'LANDSCAPING_SPECIALIST',
    'Landscaping Specialist',
    'Service employee specialized in gardens, outdoor areas, and landscaping.',
    14,
    TRUE
),
(
    'SWIMMING_POOL_TECHNICIAN',
    'Swimming Pool Technician',
    'Technician specialized in swimming-pool cleaning, maintenance, and repair.',
    15,
    TRUE
),
(
    'SMART_HOME_TECHNICIAN',
    'Smart Home Technician',
    'Technician specialized in smart-home device installation, setup, and maintenance.',
    16,
    TRUE
),
(
    'CCTV_TECHNICIAN',
    'CCTV Technician',
    'Technician specialized in security-camera installation, configuration, and maintenance.',
    17,
    TRUE
),
(
    'GENERAL_TECHNICIAN',
    'General Technician',
    'Technician responsible for other approved property services that do not have a dedicated specialization.',
    18,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 28. PRICING CONTEXT ATTRIBUTES
-- Generic engine lookup only - see backend/app/Support/Pricing.
-- Not tied to any specific service; new attributes require a
-- reviewed backend resolver, not new business-specific columns.
-- =============================================================

INSERT INTO pricing_context_attributes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'SERVICE_ZONE',
    'Service Zone',
    'The zone/area actually serving the cart or booking location. Never derived from the customer profile address.',
    TRUE
),
(
    'TIME_WINDOW',
    'Time Window',
    'The scheduling window (e.g. standard hours vs after-hours) of the confirmed appointment slot.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 29. SERVICE CAPABILITY TYPES
-- Generic classification/capability lookup that future business
-- rules query by code instead of adding dedicated boolean columns
-- (e.g. no emergency/subscription columns on services).
-- =============================================================

INSERT INTO service_capability_types (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'CART_ELIGIBLE',
    'Cart Eligible',
    'The service may be added to a regular cart-based booking.',
    TRUE
),
(
    'QUOTE_ONLY',
    'Quote Only',
    'The service always requires a custom quote and never resolves to an authoritative price.',
    TRUE
),
(
    'EMERGENCY',
    'Emergency',
    'The service represents an emergency or dangerous service request.',
    TRUE
),
(
    'SUBSCRIPTION',
    'Subscription',
    'The service is only available under an annual subscription or maintenance contract.',
    TRUE
),
(
    'REQUIRES_SITE_VISIT',
    'Requires Site Visit',
    'The service requires an in-person site visit before it can be priced or scheduled.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;


-- =============================================================
-- 30. BOOKING SOURCES
-- Distinguishes a normal paid Booking from a Booking created by
-- consuming an active Service Contract entitlement (BLUE V1
-- Phase 10). STANDARD is inserted first so it is guaranteed to be
-- id = 1, matching the smallest-safe-default convention already
-- used for the first row of every other lookup table in this file.
-- =============================================================

INSERT INTO booking_sources (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'STANDARD',
    'Standard',
    'A Booking created from a successful customer Payment.',
    1,
    TRUE
),
(
    'CONTRACT',
    'Contract',
    'A Booking created by consuming an active Service Contract entitlement, with no new customer Payment.',
    2,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 31. SERVICE CONTRACT STATUSES
-- Lifecycle of a long-term Service Contract (BLUE V1 Phase 10).
-- =============================================================

INSERT INTO service_contract_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'REQUESTED',
    'Requested',
    'The customer has requested a Service Contract; it is awaiting Admin review.',
    FALSE,
    1,
    TRUE
),
(
    'APPROVED',
    'Approved',
    'An Admin has finalized the term, covered Services and entitlements for this Contract.',
    FALSE,
    2,
    TRUE
),
(
    'PENDING_CUSTOMER_ACCEPTANCE',
    'Pending Customer Acceptance',
    'The finalized Contract has been sent to the customer and is awaiting their acceptance.',
    FALSE,
    3,
    TRUE
),
(
    'PENDING_PAYMENT',
    'Pending Payment',
    'The customer has accepted the Contract terms and must complete Stripe subscription checkout before it activates.',
    FALSE,
    4,
    TRUE
),
(
    'ACTIVE',
    'Active',
    'The customer has accepted the Contract, its Stripe subscription''s first invoice was paid, and it is currently within its term.',
    FALSE,
    5,
    TRUE
),
(
    'SUSPENDED',
    'Suspended',
    'The Contract has been temporarily suspended by an Admin; it does not authorize new Bookings.',
    FALSE,
    6,
    TRUE
),
(
    'EXPIRED',
    'Expired',
    'The Contract term has ended.',
    TRUE,
    7,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The Contract was cancelled by an Admin.',
    TRUE,
    8,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- 32. SERVICE CONTRACT BILLING STATUSES
-- Recurring Stripe subscription-billing lifecycle of one Service
-- Contract (service_contract_billings.status_id, BLUE V1 Phase 11).
-- Distinct from service_contract_statuses (the Contract's own
-- operational status) - see App\Support\Contract\Billing\
-- ContractBillingStatuses.
-- =============================================================

INSERT INTO service_contract_billing_statuses (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'PENDING_CHECKOUT',
    'Pending Checkout',
    'The billing terms are frozen but the customer has not yet started (or completed) Stripe Checkout.',
    1,
    TRUE
),
(
    'INCOMPLETE',
    'Incomplete',
    'Stripe Checkout completed and a Subscription exists, but no invoice has been confirmed paid yet.',
    2,
    TRUE
),
(
    'ACTIVE',
    'Active',
    'The subscription''s most recent invoice was paid; new Contract Bookings are authorized.',
    3,
    TRUE
),
(
    'PAST_DUE',
    'Past Due',
    'The most recent renewal invoice failed; Stripe is retrying automatically. New Contract Bookings are blocked.',
    4,
    TRUE
),
(
    'CANCEL_AT_PERIOD_END',
    'Cancel At Period End',
    'The subscription is scheduled to stop at the current paid period''s end; still authorizes Bookings until then.',
    5,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The Stripe subscription was cancelled (by the Admin, the customer, or Stripe itself); terminal.',
    6,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;


-- =============================================================
-- COMPLETE SEED TRANSACTION
-- =============================================================

COMMIT;
