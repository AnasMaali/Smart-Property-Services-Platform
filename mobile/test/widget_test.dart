import 'package:blue_customer/app/app.dart';
import 'package:blue_customer/app/app_scope.dart';
import 'package:blue_customer/app/theme/blue_theme.dart';
import 'package:blue_customer/features/account/presentation/account_page.dart';
import 'package:blue_customer/features/account/presentation/delete_account_page.dart';
import 'package:blue_customer/features/account/presentation/widgets/delete_account_widgets.dart';
import 'package:blue_customer/features/auth/presentation/otp_verify_page.dart';
import 'package:blue_customer/features/auth/presentation/signup_details_page.dart';
import 'package:blue_customer/features/auth/presentation/signup_property_page.dart';
import 'package:blue_customer/features/auth/presentation/widgets/blue_primary_button.dart';
import 'package:blue_customer/features/bookings/data/booking_models.dart';
import 'package:blue_customer/features/bookings/presentation/booking_detail_page.dart';
import 'package:blue_customer/features/bookings/presentation/bookings_page.dart';
import 'package:blue_customer/features/cart/presentation/cart_page.dart';
import 'package:blue_customer/features/auth/presentation/widgets/blue_motion.dart';
import 'package:blue_customer/features/contracts/presentation/contract_detail_page.dart';
import 'package:blue_customer/features/contracts/presentation/contracts_page.dart';
import 'package:blue_customer/features/home/presentation/widgets/home_catalog.dart';
import 'package:blue_customer/features/profile/presentation/change_phone_page.dart';
import 'package:blue_customer/features/profile/presentation/profile_page.dart';
import 'package:blue_customer/features/profile/presentation/verify_phone_otp_page.dart';
import 'package:blue_customer/features/profile/presentation/widgets/profile_widgets.dart';
import 'package:blue_customer/features/properties/presentation/my_properties_page.dart';
import 'package:blue_customer/features/properties/presentation/property_form_page.dart';
import 'package:blue_customer/features/ratings/data/rating_models.dart';
import 'package:blue_customer/features/ratings/presentation/already_rated_page.dart';
import 'package:blue_customer/features/ratings/presentation/booking_detail_ratings_page.dart';
import 'package:blue_customer/features/ratings/presentation/rate_service_sheet.dart';
import 'package:blue_customer/features/ratings/presentation/rating_submitted_sheet.dart';
import 'package:blue_customer/features/ratings/presentation/widgets/rating_widgets.dart';
import 'package:blue_customer/features/services/presentation/service_detail_page.dart';
import 'package:blue_customer/features/services/presentation/services_page.dart';
import 'package:blue_customer/features/services/presentation/widgets/services_widgets.dart';
import 'package:blue_customer/features/shell/presentation/widgets/blue_bottom_nav.dart';
import 'package:blue_customer/features/support/presentation/help_support_page.dart';
import 'package:blue_customer/features/support/presentation/new_support_request_page.dart';
import 'package:blue_customer/features/support/presentation/support_request_detail_page.dart';
import 'package:blue_customer/features/support/presentation/widgets/support_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_blue_api.dart';

void main() {
  Future<void> pumpApp(WidgetTester tester, {FakeBlueApi? api}) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      BlueApp(dependencies: buildTestDependencies(httpClient: api)),
    );
    await tester.pump(const Duration(milliseconds: 1900));
    await tester.pumpAndSettle();
  }

  testWidgets('login screen signs in against the auth API', (tester) async {
    await pumpApp(tester);
    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.text('Continue'), findsOneWidget);
    expect(find.text('Create Account'), findsOneWidget);
    expect(find.text('blue'), findsWidgets);

    await tester.enterText(find.byType(TextField).first, '501234567');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    expect(find.text('Sending code…'), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.text('Enter your code'), findsOneWidget);

    final otpField = find.descendant(
      of: find.byType(OtpVerifyPage),
      matching: find.byType(TextField),
    );
    await tester.enterText(otpField, '123456');
    await tester.pump();
    await tester.pumpAndSettle();
    expect(find.textContaining('Layla'), findsWidgets);
    expect(find.text('Search for a service'), findsOneWidget);
    expect(find.text('Service Categories'), findsOneWidget);
    expect(find.text('Available Services'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(HomeCategoryCard),
        matching: find.text('AC'),
      ),
      findsOneWidget,
    );
    expect(find.text('Home'), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byType(HomeCategoryCard),
        matching: find.text('AC'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('AC deep cleaning'), findsWidgets);

    await tester.tap(
      find.descendant(
        of: find.byType(ServicesPage),
        matching: find.text('AC deep cleaning'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('About this service'), findsOneWidget);
    expect(find.text('Add to cart'), findsOneWidget);
    final detailScroll = find.descendant(
      of: find.byType(ServiceDetailPage),
      matching: find.byWidgetPredicate(
        (widget) => widget is Scrollable && widget.axis == Axis.vertical,
      ),
    );
    await tester.scrollUntilVisible(
      find.text('Unit type'),
      240,
      scrollable: detailScroll,
    );
    expect(find.text('Unit type'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Any unit mounted above 3 m?'),
      240,
      scrollable: detailScroll,
    );
    expect(find.text('Any unit mounted above 3 m?'), findsOneWidget);
  });

  testWidgets('create account walks the 3-step BLUE Sign Up flow', (
    tester,
  ) async {
    await pumpApp(tester);
    await tester.tap(find.text('Create Account'));
    await tester.pumpAndSettle();

    expect(find.text('Create your account'), findsOneWidget);
    expect(find.text('Step 1 of 3'), findsOneWidget);
    expect(find.text('Your details'), findsOneWidget);

    final detailsContinue = find.descendant(
      of: find.byType(SignupDetailsPage),
      matching: find.widgetWithText(BluePrimaryButton, 'Continue'),
    );
    await tester.ensureVisible(detailsContinue);
    await tester.pumpAndSettle();
    await tester.tap(detailsContinue);
    await tester.pump();
    expect(
      find.text('Enter your full name as it appears on your ID.'),
      findsOneWidget,
    );
    expect(
      find.text('Check the email address — it looks incomplete.'),
      findsOneWidget,
    );
    expect(
      find.text('Enter your 9-digit mobile number. For example 50 123 4567.'),
      findsWidgets,
    );

    final fields = find.descendant(
      of: find.byType(SignupDetailsPage),
      matching: find.byType(TextField),
    );
    await tester.enterText(fields.at(0), 'Aisha Al Mansoori');
    await tester.enterText(fields.at(1), 'aisha@example.com');
    await tester.enterText(fields.at(2), '501234567');
    await tester.ensureVisible(detailsContinue);
    await tester.pumpAndSettle();
    await tester.tap(detailsContinue);
    await tester.pumpAndSettle();

    expect(find.text("Where's your property?"), findsOneWidget);
    expect(find.text('Step 2 of 3'), findsOneWidget);
    expect(find.text('Choose a city first'), findsOneWidget);

    await tester.tap(find.text('Select city'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dubai'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Select area in Dubai'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dubai Marina'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Tenant'));
    await tester.pump();
    await tester.tap(find.text('Cleaning'));
    await tester.pump();

    final propertyContinue = find.descendant(
      of: find.byType(SignupPropertyPage),
      matching: find.widgetWithText(BluePrimaryButton, 'Continue'),
    );
    await tester.ensureVisible(propertyContinue);
    await tester.pumpAndSettle();
    await tester.tap(propertyContinue);
    await tester.pump();
    expect(find.text('Sending code…'), findsOneWidget);
    await tester.pumpAndSettle();

    expect(find.text('Verify your phone'), findsOneWidget);
    expect(find.text('Step 3 of 3'), findsOneWidget);
    expect(find.text('Verify the number'), findsOneWidget);
    expect(find.textContaining('+971 50 *** 4567'), findsOneWidget);
  });

  testWidgets('empty cart matches BLUE Cart empty state', (tester) async {
    await pumpApp(tester);
    await tester.enterText(find.byType(TextField).first, '501234567');
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byType(OtpVerifyPage),
        matching: find.byType(TextField),
      ),
      '123456',
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Cart'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Your cart is empty'), findsOneWidget);
    expect(find.text('Nothing saved yet.'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(CartPage),
        matching: find.text('Browse services'),
      ),
      findsOneWidget,
    );
    expect(find.text('Proceed to checkout'), findsNothing);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Home'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Search for a service'));
    await tester.pumpAndSettle();
    expect(find.text('Services'), findsOneWidget);
    await tester.tap(find.byType(ServicesBackButton));
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Cart'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(CartPage),
        matching: find.text('Browse services'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Services'), findsOneWidget);
  });

  testWidgets('adding a service shows the priced BLUE Cart', (tester) async {
    await pumpApp(tester);
    await tester.enterText(find.byType(TextField).first, '501234567');
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byType(OtpVerifyPage),
        matching: find.byType(TextField),
      ),
      '123456',
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(
        of: find.byType(HomeCategoryCard),
        matching: find.text('AC'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(ServicesPage),
        matching: find.text('AC deep cleaning'),
      ),
    );
    await tester.pumpAndSettle();

    final detailScroll = find
        .descendant(
          of: find.byType(ServiceDetailPage),
          matching: find.byWidgetPredicate(
            (widget) => widget is Scrollable && widget.axis == Axis.vertical,
          ),
        )
        .first;
    await tester.scrollUntilVisible(
      find.text('Split'),
      240,
      scrollable: detailScroll,
    );
    await tester.tap(find.text('Split'));
    await tester.pump();
    await tester.scrollUntilVisible(
      find.text('No'),
      240,
      scrollable: detailScroll,
    );
    await tester.tap(find.text('No'));
    await tester.pump();
    await tester.tap(find.text('Add to cart'));
    await tester.pumpAndSettle();
    expect(find.text('Added to cart'), findsOneWidget);

    await tester.tap(find.text('View cart'));
    await tester.pumpAndSettle();
    expect(find.text('Your cart is empty'), findsNothing);
    expect(find.text('1 service · 1 unit'), findsOneWidget);
    expect(find.text('AC deep cleaning'), findsWidgets);
    expect(find.text('AED 120'), findsWidgets);
    expect(find.text('Proceed to checkout'), findsOneWidget);
    expect(find.text('Summary'), findsOneWidget);
    expect(find.text('Change details'), findsOneWidget);

    await tester.tap(find.text('Proceed to checkout'));
    await tester.pumpAndSettle();
    expect(find.text('Checkout'), findsOneWidget);
    expect(
      find.text('Two things to confirm: location and appointment.'),
      findsOneWidget,
    );
    expect(find.text('Service location'), findsOneWidget);
    expect(find.text('Needed'), findsWidgets);
    expect(find.text('Add location'), findsOneWidget);
    expect(find.text('Appointment'), findsOneWidget);
    expect(find.text('Choose appointment'), findsOneWidget);
    expect(find.text('Your order'), findsOneWidget);
    expect(find.text('Edit cart'), findsOneWidget);
    expect(find.text('Continue to payment'), findsOneWidget);
    expect(find.text('Location and time needed'), findsOneWidget);

    await tester.tap(find.text('Choose appointment'));
    await tester.pumpAndSettle();
    expect(find.text('Choose appointment'), findsOneWidget);
    expect(find.text('Choose a date'), findsOneWidget);
    expect(find.text('Available times'), findsOneWidget);
    expect(find.text('Gulf Standard Time'), findsOneWidget);
    expect(find.text('Reserve this time'), findsOneWidget);
    expect(find.text('No time selected'), findsOneWidget);
    expect(find.text('Select a time to continue'), findsOneWidget);
  });

  testWidgets(
    'payment screen follows the BLUE Payment ready and success frames',
    (tester) async {
      await pumpApp(
        tester,
        api: FakeBlueApi(readyCheckout: true, delay: Duration.zero),
      );
      await tester.enterText(find.byType(TextField).first, '501234567');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.descendant(
          of: find.byType(OtpVerifyPage),
          matching: find.byType(TextField),
        ),
        '123456',
      );
      await tester.pumpAndSettle();

      await tester.tap(
        find.descendant(
          of: find.byType(HomeCategoryCard),
          matching: find.text('AC'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(
        find.descendant(
          of: find.byType(ServicesPage),
          matching: find.text('AC deep cleaning'),
        ),
      );
      await tester.pumpAndSettle();

      final detailScroll = find
          .descendant(
            of: find.byType(ServiceDetailPage),
            matching: find.byWidgetPredicate(
              (widget) => widget is Scrollable && widget.axis == Axis.vertical,
            ),
          )
          .first;
      await tester.scrollUntilVisible(
        find.text('Split'),
        240,
        scrollable: detailScroll,
      );
      await tester.tap(find.text('Split'));
      await tester.pump();
      await tester.scrollUntilVisible(
        find.text('No'),
        240,
        scrollable: detailScroll,
      );
      await tester.tap(find.text('No'));
      await tester.pump();
      await tester.tap(find.text('Add to cart'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('View cart'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Proceed to checkout'));
      await tester.pumpAndSettle();
      expect(find.text('Continue to payment'), findsOneWidget);

      await tester.tap(find.text('Continue to payment'));
      await tester.pumpAndSettle();
      expect(find.text('Total to pay'), findsOneWidget);
      expect(find.text('AED 120'), findsWidgets);
      expect(find.text("You're paying for"), findsOneWidget);
      expect(find.text('View details'), findsOneWidget);
      expect(find.text('Payment method'), findsWidgets);
      expect(find.text('Card payment'), findsOneWidget);
      expect(find.text('Pay securely via Stripe'), findsOneWidget);
      expect(find.text('Change'), findsOneWidget);
      expect(find.text('Pay AED 120'), findsOneWidget);
      expect(
        find.text('Tapping Pay charges AED 120 to Card payment.'),
        findsOneWidget,
      );

      await tester.tap(find.text('Pay AED 120'));
      await tester.pumpAndSettle();
      expect(find.text('Payment successful'), findsOneWidget);
      expect(find.text('View booking'), findsOneWidget);
      expect(find.text('Back to home'), findsOneWidget);
      expect(find.text('Booking'), findsOneWidget);
      expect(find.text('BLU-4827-QK'), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 200));
    },
  );

  Future<void> _signIn(WidgetTester tester) async {
    await tester.enterText(find.byType(TextField).first, '501234567');
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byType(OtpVerifyPage),
        matching: find.byType(TextField),
      ),
      '123456',
    );
    await tester.pumpAndSettle();
  }

  testWidgets('empty bookings matches BLUE Bookings empty state', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Bookings'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Bookings'), findsWidgets);
    expect(find.text('No bookings yet'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Current'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Past'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Browse services'),
      ),
      findsOneWidget,
    );

    await tester.tap(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Browse services'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Services'), findsOneWidget);
  });

  testWidgets('bookings list splits current and past the BLUE way', (
    tester,
  ) async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day, 16);
    await pumpApp(
      tester,
      api: FakeBlueApi(
        bookings: [
          fakeBooking(
            uuid: 'b-today',
            number: 'BLU-4827-QK',
            status: 'PAID',
            service: 'AC deep cleaning',
            startsAt: today,
            endsAt: today.add(const Duration(hours: 2)),
          ),
          fakeBooking(
            uuid: 'b-later',
            number: 'BLU-4901-TD',
            status: 'ASSIGNED',
            service: 'Deep cleaning',
            startsAt: today.add(const Duration(days: 3)),
            extraServices: ['Sofa cleaning', 'Window cleaning'],
            building: 'Marina Tower 4',
          ),
          fakeBooking(
            uuid: 'b-done',
            number: 'BLU-4612-WZ',
            status: 'COMPLETED',
            service: 'AC deep cleaning',
            startsAt: today.subtract(const Duration(days: 12)),
          ),
          fakeBooking(
            uuid: 'b-cancel',
            number: 'BLU-4402-JN',
            status: 'CANCELLED',
            service: 'Move-in cleaning',
            startsAt: today.subtract(const Duration(days: 25)),
            area: 'Jumeirah Village Circle',
          ),
        ],
      ),
    );
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Bookings'),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Current'),
      ),
      findsOneWidget,
    );
    expect(find.text('HAPPENING TODAY'), findsOneWidget);
    expect(find.text('COMING UP'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Today'),
      ),
      findsWidgets,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('AC deep cleaning'),
      ),
      findsWidgets,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('BLU-4827-QK'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Scheduled'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Deep cleaning'),
      ),
      findsOneWidget,
    );
    expect(find.text('+ 2 more services'), findsOneWidget);
    expect(find.text('3 services in this booking'), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Past'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('2 BOOKINGS'), findsOneWidget);
    expect(find.text('Completed'), findsOneWidget);
    expect(find.text('Cancelled'), findsOneWidget);
    expect(
      find.text(
        'Cancelled bookings stay here too — nothing is removed from your history.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('bookings error state can retry', (tester) async {
    await pumpApp(tester, api: FakeBlueApi(failBookings: true));
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Bookings'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text("We couldn't load your bookings"), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Current'),
      ),
      findsNothing,
    );
  });

  testWidgets('empty contracts matches BLUE Contracts empty state', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Contracts'), findsWidgets);
    expect(find.text('No contracts yet'), findsOneWidget);
    expect(
      find.text(
        "A service contract covers recurring work at your property over a fixed period. When you have one, it'll appear here with what it covers and how long it runs.",
      ),
      findsOneWidget,
    );
    expect(
      find.text("We'll review your request and send a quote you can accept here."),
      findsOneWidget,
    );
    expect(find.text('Request a contract'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Current'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Past'),
      ),
      findsNothing,
    );

    await tester.tap(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Explore services'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Services'), findsOneWidget);
  });

  testWidgets('contracts list splits current and past the BLUE way', (
    tester,
  ) async {
    final now = DateTime.now();
    final start = DateTime(
      now.year,
      now.month,
      now.day,
    ).subtract(const Duration(days: 20));
    final future = DateTime(
      now.year,
      now.month,
      now.day,
    ).add(const Duration(days: 40));
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-active',
            status: 'ACTIVE',
            name: 'Annual AC care',
            extraServices: ['Filter replacement', 'Coil treatment'],
            startsAt: start,
            billingStatus: 'ACTIVE',
          ),
          fakeContract(
            uuid: 'c-due',
            status: 'PENDING_PAYMENT',
            name: 'Quarterly deep cleaning',
            startsAt: start,
            billingStatus: 'PENDING_CHECKOUT',
            billingAmount: '1200.00',
            periodEnd: DateTime(now.year, now.month + 1, 1),
          ),
          fakeContract(
            uuid: 'c-start',
            status: 'ACTIVE',
            name: 'Villa garden care',
            extraServices: ['Irrigation check'],
            startsAt: future,
            billingStatus: 'ACTIVE',
          ),
          fakeContract(
            uuid: 'c-ended',
            status: 'EXPIRED',
            name: 'Annual AC care',
            extraServices: ['Filter replacement'],
            startsAt: DateTime(now.year - 1, 8, 25),
            endsAt: DateTime(now.year, 8, 24),
          ),
          fakeContract(
            uuid: 'c-cancelled',
            status: 'CANCELLED',
            name: 'Quarterly deep cleaning',
            startsAt: DateTime(now.year, 1, 12),
            updatedAt: DateTime(now.year, 3, 14),
          ),
        ],
      ),
    );
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Current'),
      ),
      findsOneWidget,
    );
    expect(
      find.text("What's covered at your property, and for how long."),
      findsNothing,
    );
    expect(find.text('One contract needs something from you.'), findsOneWidget);
    expect(find.text('NEEDS YOUR ATTENTION'), findsOneWidget);
    expect(find.text('RUNNING'), findsOneWidget);
    expect(find.text('Payment due'), findsOneWidget);
    expect(find.text('AED 1,200'), findsOneWidget);
    expect(find.text('Pay now'), findsOneWidget);
    final contractsScroll = find.descendant(
      of: find.byType(ContractsPage),
      matching: find.byType(Scrollable),
    );
    await tester.drag(contractsScroll, const Offset(0, -360));
    await tester.pumpAndSettle();
    expect(find.text('Annual AC care'), findsWidgets);
    expect(find.text('Active'), findsOneWidget);
    expect(find.textContaining('Starts '), findsWidgets);
    expect(find.text('BILLING'), findsWidgets);
    expect(find.text('Paid'), findsWidgets);
    expect(find.text('Cover begins on the start date.'), findsOneWidget);
    expect(find.text('+ 1 more included service'), findsOneWidget);

    await tester.drag(contractsScroll, const Offset(0, 360));
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Past'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('2 CONTRACTS'), findsOneWidget);
    expect(find.text('Ended'), findsOneWidget);
    expect(find.text('Cancelled'), findsOneWidget);
    expect(
      find.text('Ended and cancelled contracts stay here for your records.'),
      findsOneWidget,
    );
    expect(find.text('BILLING'), findsNothing);
  });

  testWidgets('contracts action states keep approval and billing separate', (
    tester,
  ) async {
    final now = DateTime.now();
    final start = DateTime(now.year, now.month + 1, 1);
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-accept',
            status: 'PENDING_CUSTOMER_ACCEPTANCE',
            name: 'Annual AC care',
            extraServices: ['Filter replacement'],
            startsAt: start,
          ),
          fakeContract(
            uuid: 'c-paused',
            status: 'SUSPENDED',
            name: 'Home maintenance',
            extraServices: ['Plumbing repair', 'Electrical repair', 'Painting'],
            startsAt: DateTime(
              now.year,
              now.month,
              now.day,
            ).subtract(const Duration(days: 40)),
            billingStatus: 'PAST_DUE',
            billingAmount: '2400.00',
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('2 contracts need something from you.'), findsOneWidget);
    expect(find.text('NEED YOUR ATTENTION'), findsOneWidget);
    expect(find.text('Needs your approval'), findsOneWidget);
    expect(find.text('Review and accept'), findsOneWidget);
    expect(
      find.text("Review what's covered and confirm to start this contract."),
      findsOneWidget,
    );
    expect(find.textContaining('Proposed:'), findsOneWidget);
    expect(find.text('Paused'), findsOneWidget);
    expect(find.text('Payment failed'), findsOneWidget);
    expect(find.text('AED 2,400'), findsOneWidget);
    expect(
      find.text('Cover is on hold until this payment goes through.'),
      findsOneWidget,
    );
    expect(find.text('+ 2 more included services'), findsOneWidget);
    expect(find.text('Update payment'), findsOneWidget);
    expect(find.text('Pay now'), findsNothing);
  });

  testWidgets('no active contracts still offers past history', (tester) async {
    final now = DateTime.now();
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-ended',
            status: 'EXPIRED',
            name: 'Annual AC care',
            startsAt: DateTime(now.year - 1, 8, 25),
            endsAt: DateTime(now.year, 8, 24),
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('No active contracts'), findsOneWidget);
    expect(find.text('See past contracts'), findsOneWidget);
    await tester.tap(find.text('See past contracts'));
    await tester.pumpAndSettle();
    expect(find.text('1 CONTRACT'), findsOneWidget);
    expect(find.text('Ended'), findsOneWidget);
  });

  testWidgets('contracts error state can retry', (tester) async {
    await pumpApp(tester, api: FakeBlueApi(failContracts: true));
    await _signIn(tester);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text("We couldn't load your contracts"), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(ContractsPage),
        matching: find.text('Current'),
      ),
      findsNothing,
    );
  });

  testWidgets('contract detail matches BLUE active state', (tester) async {
    final now = DateTime.now();
    final start = DateTime(
      now.year,
      now.month,
      now.day,
    ).subtract(const Duration(days: 12));
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-active',
            status: 'ACTIVE',
            name: 'Annual AC care',
            extraServices: ['Filter replacement'],
            startsAt: start,
            number: 'CTR-2026-0142',
            billingAmount: '4800.00',
            visits: const [4, 2],
            descriptions: const [
              'All indoor units, filters washed and refitted',
              'Premium filters, supplied and fitted',
            ],
            bookings: const [
              {'uuid': 'b1', 'status': 'CONFIRMED', 'booking_number': 'BK-1'},
              {'uuid': 'b2', 'status': 'CONFIRMED', 'booking_number': 'BK-2'},
            ],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Annual AC care'));
    await tester.pumpAndSettle();

    expect(find.byType(ContractDetailPage), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('CTR-2026-0142'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Active'),
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'Your services are covered. Book a covered visit whenever you need one.',
      ),
      findsOneWidget,
    );
    expect(find.text('Coverage period'), findsOneWidget);
    expect(
      find.text('Cover ends on the last day of the period.'),
      findsOneWidget,
    );
    expect(find.text("What's covered · 2 services"), findsOneWidget);
    expect(find.text('4 visits'), findsOneWidget);
    expect(find.text('2 visits'), findsOneWidget);
    expect(
      find.text('All indoor units, filters washed and refitted'),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Paid'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('AED 4,800'),
      ),
      findsOneWidget,
    );
    expect(find.text('Contract history'), findsOneWidget);
    expect(find.text('Contract activated'), findsOneWidget);
    expect(
      find.text('2 visits have been booked under this contract so far.'),
      findsOneWidget,
    );
    expect(find.text('View bookings'), findsOneWidget);
    expect(find.text('Get help with this contract'), findsOneWidget);
    expect(find.text('Accept contract'), findsNothing);
    expect(find.textContaining('Pay '), findsNothing);
  });

  testWidgets('contract detail acceptance stays disabled until consent', (
    tester,
  ) async {
    final now = DateTime.now();
    final start = DateTime(now.year, now.month + 1, 1);
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-accept',
            status: 'PENDING_CUSTOMER_ACCEPTANCE',
            name: 'Annual AC care',
            extraServices: ['Filter replacement'],
            startsAt: start,
            number: 'CTR-2026-0207',
            termsReference: 'CTR-2026-0207-T1',
            visits: const [4, 2],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Annual AC care'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Needs your approval'),
      ),
      findsOneWidget,
    );
    expect(
      find.text('Nothing is charged and nothing is covered until you accept.'),
      findsOneWidget,
    );
    expect(find.text('Proposed period'), findsOneWidget);
    expect(find.text('Before you accept'), findsOneWidget);
    expect(find.text('Terms reference CTR-2026-0207-T1'), findsOneWidget);
    expect(find.text('Accept contract'), findsOneWidget);
    expect(find.text('Tick the box above to continue.'), findsOneWidget);

    await tester.tap(find.text('Accept contract'));
    await tester.pumpAndSettle();
    expect(find.text('Before you accept'), findsOneWidget);

    final consent = find.byKey(const Key('contract-consent'));
    await tester.ensureVisible(consent);
    await tester.pumpAndSettle();
    await tester.tap(consent);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Accept contract'));
    await tester.pumpAndSettle();

    expect(find.text('Before you accept'), findsNothing);
    expect(find.text('Accepted by you'), findsOneWidget);
    expect(find.textContaining('Pay '), findsOneWidget);
  });

  testWidgets('contract detail payment due keeps cover active', (tester) async {
    final now = DateTime.now();
    final start = DateTime(
      now.year,
      now.month,
      now.day,
    ).subtract(const Duration(days: 40));
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-due',
            status: 'ACTIVE',
            name: 'Quarterly deep cleaning',
            startsAt: start,
            billingStatus: 'PAST_DUE',
            billingAmount: '1200.00',
            periodEnd: DateTime(now.year, now.month + 1, 1),
            visits: const [4],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Quarterly deep cleaning').first);
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Active'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Payment due'),
      ),
      findsOneWidget,
    );
    expect(find.text('AED 1,200'), findsWidgets);
    expect(find.textContaining('Cover continues meanwhile.'), findsOneWidget);
    expect(find.text('Pay AED 1,200'), findsOneWidget);
    expect(find.text("Opens BLUE's secure payment sheet."), findsOneWidget);
  });

  testWidgets('paused contract detail asks to update payment', (tester) async {
    final now = DateTime.now();
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-paused',
            status: 'SUSPENDED',
            name: 'Home maintenance',
            extraServices: ['Plumbing repair', 'Electrical repair'],
            startsAt: DateTime(
              now.year,
              now.month,
              now.day,
            ).subtract(const Duration(days: 40)),
            billingStatus: 'PAST_DUE',
            billingAmount: '2400.00',
            visits: const [6, 4, 4],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Home maintenance'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Paused'),
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'Your bank declined the last payment, so cover is paused. Updating the payment restores it straight away.',
      ),
      findsOneWidget,
    );
    expect(find.text("Paused days don't extend the period."), findsOneWidget);
    expect(
      find.text("Covered visits can't be booked while the contract is paused."),
      findsOneWidget,
    );
    expect(find.text('Payment needs attention'), findsOneWidget);
    expect(find.text('Update payment'), findsOneWidget);
    expect(find.text('Contract paused'), findsOneWidget);
  });

  testWidgets('ended contract detail keeps usage and history', (tester) async {
    final now = DateTime.now();
    await pumpApp(
      tester,
      api: FakeBlueApi(
        contracts: [
          fakeContract(
            uuid: 'c-ended',
            status: 'EXPIRED',
            name: 'Annual AC care',
            extraServices: ['Filter replacement'],
            startsAt: DateTime(now.year - 1, 8, 25),
            endsAt: DateTime(now.year, 8, 24),
            visits: const [4, 2],
            used: const [4, 1],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Past'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Annual AC care'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(ContractDetailPage),
        matching: find.text('Ended'),
      ),
      findsOneWidget,
    );
    expect(
      find.textContaining('This contract ran its full term'),
      findsOneWidget,
    );
    expect(find.text('4 used'), findsOneWidget);
    expect(find.text('1 of 2 used'), findsOneWidget);
    expect(find.text('Contract ended'), findsOneWidget);
    expect(find.text('Accept contract'), findsNothing);
    expect(find.text('Update payment'), findsNothing);
  });

  testWidgets('contract detail retryable error and not found', (tester) async {
    final now = DateTime.now();
    await pumpApp(
      tester,
      api: FakeBlueApi(
        failContractDetail: true,
        contracts: [
          fakeContract(
            uuid: 'c-active',
            status: 'ACTIVE',
            name: 'Annual AC care',
            startsAt: DateTime(
              now.year,
              now.month,
              now.day,
            ).subtract(const Duration(days: 8)),
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Contracts'),
      ),
    );
    await tester.pumpAndSettle();
    final contractsContext = tester.element(find.byType(ContractsPage));
    Navigator.of(contractsContext).push(
      BluePageRoute<void>(
        builder: (_) => const ContractDetailPage(contractUuid: 'c-active'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text("We couldn't load this contract"), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(find.text('Back to contracts'), findsOneWidget);

    Navigator.of(tester.element(find.byType(ContractDetailPage))).push(
      BluePageRoute<void>(
        builder: (_) => const ContractDetailPage(contractUuid: 'missing'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text("This contract isn't available"), findsOneWidget);
  });

  testWidgets('my properties list, add validation, and save match BLUE', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('My properties'));
    await tester.pumpAndSettle();

    expect(find.text('My properties'), findsWidgets);
    expect(find.text('3 saved properties'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(MyPropertiesPage),
        matching: find.text('Apartment'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(MyPropertiesPage),
        matching: find.text('Dubai Marina · Dubai'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(MyPropertiesPage),
        matching: find.text('Marina Tower 4 · Unit 1204'),
      ),
      findsOneWidget,
    );
    expect(find.text('Owner'), findsWidgets);
    expect(
      find.descendant(
        of: find.byType(MyPropertiesPage),
        matching: find.text('Villa'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(MyPropertiesPage),
        matching: find.text('Office'),
      ),
      findsOneWidget,
    );
    expect(find.text('+ Add'), findsOneWidget);

    await tester.tap(find.text('+ Add'));
    await tester.pumpAndSettle();
    expect(find.text('Add property'), findsOneWidget);
    expect(find.text('Save a place you book services for.'), findsOneWidget);
    expect(find.text('Save property'), findsOneWidget);

    await tester.tap(find.text('Save property'));
    await tester.pumpAndSettle();
    expect(find.text('Choose a property type.'), findsOneWidget);
    expect(
      find.text('Tell us how this property relates to you.'),
      findsOneWidget,
    );
    expect(find.text('Select a city.'), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byType(PropertyFormPage),
        matching: find.text('Villa'),
      ),
    );
    await tester.pump();
    await tester.tap(find.text('Select relationship'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Owner').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Select city'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dubai').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Select area'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Palm Jumeirah').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save property'));
    await tester.pumpAndSettle();

    expect(find.text('4 saved properties'), findsOneWidget);
    expect(find.text('Palm Jumeirah · Dubai'), findsWidgets);
  });

  testWidgets('my properties empty state matches BLUE', (tester) async {
    await pumpApp(tester, api: FakeBlueApi(properties: const []));
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('My properties'));
    await tester.pumpAndSettle();

    expect(find.text('No properties saved'), findsOneWidget);
    expect(find.text('Add property'), findsOneWidget);
    expect(find.text('+ Add'), findsNothing);
  });

  testWidgets('my properties load error matches BLUE', (tester) async {
    await pumpApp(tester, api: FakeBlueApi(failProperties: true));
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('My properties'));
    await tester.pumpAndSettle();
    expect(find.text("We couldn't load your properties"), findsOneWidget);
    expect(
      find.text(
        'Nothing has been lost — this is only a problem loading the page. Check your connection and try again.',
      ),
      findsOneWidget,
    );
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('profile back restores account bottom nav', (tester) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();

    final accountContext = tester.element(find.byType(AccountPage));
    final shell = AppScope.of(accountContext).shell;
    expect(shell.hideNav.value, isFalse);

    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(ProfilePage), findsOneWidget);
    expect(shell.hideNav.value, isTrue);

    await tester.tap(find.byType(ProfileBackButton));
    await tester.pumpAndSettle();
    expect(find.byType(ProfilePage), findsNothing);
    expect(find.byType(AccountPage), findsOneWidget);
    expect(shell.hideNav.value, isFalse);

    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Home'),
      ),
    );
    await tester.pumpAndSettle();
    expect(shell.tab.value, BlueTab.home);
  });

  testWidgets('profile matches BLUE default, dirty, validation, and save', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(ProfilePage), findsOneWidget);
    expect(find.text('The details BLUE has about you.'), findsWidgets);
    expect(find.text('Layla Hassan'), findsOneWidget);
    expect(find.text('layla@example.com'), findsOneWidget);
    expect(find.text('+971 50 123 4567'), findsOneWidget);
    expect(find.text('Change'), findsOneWidget);
    expect(
      find.text('Where we send booking and contract confirmations.'),
      findsOneWidget,
    );
    expect(
      find.text(
        'This is how you sign in, so changing it needs a code sent to the new number.',
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ProfilePage),
        matching: find.text('What can we help with?'),
      ),
      findsOneWidget,
    );
    expect(find.text('OPTIONAL'), findsOneWidget);
    expect(find.text('Cleaning'), findsWidgets);
    expect(find.text('AC services'), findsWidgets);
    expect(find.text('+4 more'), findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);
    expect(find.text('Change something to save.'), findsOneWidget);

    await tester.ensureVisible(find.text('+4 more'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('+4 more'));
    await tester.pumpAndSettle();
    expect(
      find.text('Pick as many as you like, or none at all.'),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(ProfilePage),
        matching: find.text('Plumbing'),
      ),
      findsWidgets,
    );
    expect(find.text('Done · 2 selected'), findsOneWidget);
    await tester.tap(
      find
          .descendant(
            of: find.byType(ProfilePage),
            matching: find.text('Plumbing'),
          )
          .last,
    );
    await tester.pump();
    expect(find.text('Done · 3 selected'), findsOneWidget);
    await tester.tap(find.text('Done · 3 selected'));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byType(ProfilePage),
        matching: find.text('Plumbing'),
      ),
      findsWidgets,
    );

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-email')),
        matching: find.byType(TextField),
      ),
      'layla.new@example.com',
    );
    await tester.pump();
    expect(find.text('Change something to save.'), findsNothing);

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-name')),
        matching: find.byType(TextField),
      ),
      'Layla',
    );
    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-email')),
        matching: find.byType(TextField),
      ),
      'not-an-email',
    );
    await tester.tap(find.text('Save changes'));
    await tester.pump();
    expect(
      find.text('Enter your full name as it appears on your ID.'),
      findsOneWidget,
    );
    expect(
      find.text('Check this email address — it looks incomplete.'),
      findsOneWidget,
    );

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-name')),
        matching: find.byType(TextField),
      ),
      'Layla Hassan',
    );
    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-email')),
        matching: find.byType(TextField),
      ),
      'layla.new@example.com',
    );
    await tester.tap(find.text('Save changes'));
    await tester.pump();
    expect(find.text('Saving...'), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.text('Profile updated'), findsOneWidget);
  });

  testWidgets('change phone number matches BLUE and updates after OTP', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Change'));
    await tester.pumpAndSettle();

    expect(find.byType(ChangePhonePage), findsOneWidget);
    expect(find.text('Change phone number'), findsOneWidget);
    expect(
      find.text(
        "Enter the new number you'll use to sign in. We'll send a code to confirm it.",
      ),
      findsOneWidget,
    );
    expect(find.text('New phone number'), findsOneWidget);
    expect(find.text('+971'), findsOneWidget);
    expect(
      find.text(
        'Your current number stays active until the new one is confirmed.',
      ),
      findsOneWidget,
    );
    expect(find.text('Continue'), findsOneWidget);

    await tester.tap(find.byKey(const Key('change-phone-continue')));
    await tester.pump();
    expect(find.byType(ChangePhonePage), findsOneWidget);
    expect(find.byType(OtpVerifyPage), findsNothing);

    await tester.enterText(
      find.descendant(
        of: find.byType(ChangePhonePage),
        matching: find.byType(TextField),
      ),
      '50',
    );
    await tester.pump();
    FocusScope.of(tester.element(find.byType(ChangePhonePage))).unfocus();
    await tester.pump();
    expect(
      find.text(
        'Enter your 9-digit UAE mobile number, for example 50 123 4567.',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'Your current number stays active until the new one is confirmed.',
      ),
      findsNothing,
    );

    await tester.enterText(
      find.descendant(
        of: find.byType(ChangePhonePage),
        matching: find.byType(TextField),
      ),
      '501234567',
    );
    await tester.pump();
    expect(
      find.text(
        'Your current number stays active until the new one is confirmed.',
      ),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('change-phone-continue')));
    await tester.pump();
    await tester.pumpAndSettle();
    expect(
      find.text(
        'The new phone number must be different from your current phone number.',
      ),
      findsOneWidget,
    );

    await tester.enterText(
      find.descendant(
        of: find.byType(ChangePhonePage),
        matching: find.byType(TextField),
      ),
      '509876543',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('change-phone-continue')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.byType(VerifyPhoneOtpPage), findsOneWidget);
    expect(find.text('Verify phone number'), findsOneWidget);
    expect(find.text('Edit phone number'), findsOneWidget);
    expect(find.textContaining('+971 50 987 6543'), findsOneWidget);
    expect(
      find.text('Codes expire after 10 minutes. Never share it with anyone.'),
      findsOneWidget,
    );

    await tester.enterText(
      find.descendant(
        of: find.byType(VerifyPhoneOtpPage),
        matching: find.byType(TextField),
      ),
      '123456',
    );
    await tester.pump();
    expect(find.byType(VerifyPhoneOtpPage), findsOneWidget);
    await tester.tap(find.byKey(const Key('change-phone-verify')));
    await tester.pump();
    expect(find.text('Verifying…'), findsOneWidget);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 1400));
    await tester.pumpAndSettle();
    expect(find.byType(ProfilePage), findsOneWidget);
    expect(find.text('+971 50 987 6543'), findsOneWidget);
    expect(find.text('Phone number updated'), findsOneWidget);
  });

  testWidgets('profile load error and interests sheet match BLUE', (
    tester,
  ) async {
    await pumpApp(tester, api: FakeBlueApi(failProfile: true));
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text("We couldn't load your profile"), findsOneWidget);
    expect(
      find.text(
        'Your details are safe — this is only a problem loading the page. Check your connection and try again.',
      ),
      findsOneWidget,
    );
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('profile save failure keeps edits on the BLUE strip', (
    tester,
  ) async {
    await pumpApp(tester, api: FakeBlueApi(failProfileSave: true));
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('profile-email')),
        matching: find.byType(TextField),
      ),
      'layla.new@example.com',
    );
    await tester.pump();
    expect(find.text('Change something to save.'), findsNothing);
    await tester.tap(find.text('Save changes'));
    await tester.pump();
    expect(find.text('Saving...'), findsOneWidget);
    await tester.pumpAndSettle();
    expect(
      find.text(
        "We couldn't save your profile. Your changes are still here — try again.",
      ),
      findsOneWidget,
    );
    expect(find.text('layla.new@example.com'), findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);
  });

  testWidgets('delete account matches BLUE confirmation, then OTP', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Delete account'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(DeleteAccountPage), findsOneWidget);
    expect(
      find.text(
        'This closes your BLUE account and signs you out on every device.',
      ),
      findsOneWidget,
    );
    expect(
      find.textContaining('saved properties and service interests'),
      findsOneWidget,
    );
    expect(
      find.textContaining('invoices and signed contracts'),
      findsOneWidget,
    );
    expect(
      find.textContaining('Scheduled visits and running contracts'),
      findsOneWidget,
    );
    expect(
      find.text(
        "Once deletion is complete it can't be undone, and the same phone number will start a new account from scratch.",
      ),
      findsOneWidget,
    );
    expect(
      find.text('You can come back to this any time from Account.'),
      findsOneWidget,
    );
    expect(find.text('Keep my account'), findsOneWidget);
    expect(find.text('Change password'), findsNothing);

    await tester.tap(find.text('Keep my account'));
    await tester.pumpAndSettle();
    expect(find.byType(DeleteAccountPage), findsNothing);
    expect(find.byType(AccountPage), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Delete account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('delete-account-open')));
    await tester.pumpAndSettle();

    expect(find.text('Delete your account?'), findsOneWidget);
    expect(
      find.text(
        "Your BLUE account, saved properties and preferences will be permanently deleted. This can't be undone.",
      ),
      findsOneWidget,
    );

    await tester.tap(
      find.descendant(
        of: find.byType(DeleteConfirmSheet),
        matching: find.text('Keep my account'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Delete your account?'), findsNothing);
    expect(find.byType(DeleteAccountPage), findsOneWidget);

    await tester.tap(find.byKey(const Key('delete-account-open')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('delete-account-confirm')));
    await tester.pump();
    expect(find.text('Deleting…'), findsOneWidget);
    await tester.pumpAndSettle();

    expect(find.byType(OtpVerifyPage), findsOneWidget);
    expect(find.text('Enter your code'), findsOneWidget);
    expect(find.text('Edit phone number'), findsNothing);
    expect(find.text('Change password'), findsNothing);

    await tester.enterText(
      find.descendant(
        of: find.byType(OtpVerifyPage),
        matching: find.byType(TextField),
      ),
      '123456',
    );
    await tester.pumpAndSettle();
    expect(find.text('Welcome back'), findsOneWidget);
  });

  testWidgets('help and support matches BLUE list, compose, and thread', (
    tester,
  ) async {
    await pumpApp(tester);
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Account'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AccountPage),
        matching: find.text('Help & Support'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HelpSupportPage), findsOneWidget);
    expect(
      find.text("Tell us what's wrong and we'll come back to you here."),
      findsOneWidget,
    );
    expect(find.text('Create support request'), findsOneWidget);
    expect(
      find.text('Usually answered within one working day.'),
      findsOneWidget,
    );
    expect(find.text('YOUR REQUESTS'), findsOneWidget);
    expect(find.text('In progress'), findsOneWidget);
    expect(
      find.text('AC service didn’t cover the second unit'),
      findsOneWidget,
    );
    expect(find.text('Updated 2 days ago · 3 messages'), findsOneWidget);
    expect(find.text('Open'), findsOneWidget);
    expect(find.text('Invoice for August cleaning contract'), findsOneWidget);
    expect(find.text('Sent 24 Aug 2026 · awaiting reply'), findsOneWidget);
    expect(find.text('Resolved'), findsOneWidget);
    expect(find.text('Reschedule a plumbing visit'), findsOneWidget);
    expect(find.text('Closed 11 Aug 2026'), findsOneWidget);
    expect(
      find.text('Resolved requests stay here for your records.'),
      findsOneWidget,
    );

    await tester.ensureVisible(find.text('Updated 2 days ago · 3 messages'));
    await tester.tap(find.text('Updated 2 days ago · 3 messages'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('support-detail-req-2481')), findsOneWidget);
    expect(find.text('REQ-2481'), findsOneWidget);
    expect(find.text('In progress'), findsWidgets);
    expect(find.textContaining('opened 24 Aug 2026'), findsOneWidget);
    expect(find.text('You'), findsWidgets);
    expect(find.text('BLUE Support'), findsWidgets);
    expect(
      find.text(
        'The technician cleaned the living room unit but said the bedroom unit wasn’t on the job sheet. Both units were included when I booked.',
      ),
      findsOneWidget,
    );
    expect(find.text('Write a reply…'), findsOneWidget);

    await tester.enterText(
      find.descendant(
        of: find.byType(SupportRequestDetailPage),
        matching: find.byType(TextField),
      ),
      'Please use the service lift when you come back.',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('support-send')));
    await tester.pumpAndSettle();
    expect(
      find.text('Please use the service lift when you come back.'),
      findsOneWidget,
    );
    expect(find.text('Just now'), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byType(SupportRequestDetailPage),
        matching: find.byType(SupportBackButton),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(HelpSupportPage), findsOneWidget);

    await tester.tap(find.text('Create support request'));
    await tester.pumpAndSettle();
    expect(find.byType(NewSupportRequestPage), findsOneWidget);
    expect(find.text('New support request'), findsOneWidget);
    expect(
      find.text('One request per issue keeps the thread easy to follow.'),
      findsOneWidget,
    );
    expect(find.text('Subject'), findsOneWidget);
    expect(find.text('Short summary of the issue'), findsOneWidget);
    expect(find.text('Message'), findsOneWidget);
    expect(
      find.text('What happened, and which property or booking it relates to.'),
      findsOneWidget,
    );
    expect(
      find.text(
        "We reply in this request, and you'll get a notification when we do.",
      ),
      findsOneWidget,
    );
    expect(find.text('Submit request'), findsOneWidget);

    await tester.tap(find.text('Submit request'));
    await tester.pump();
    expect(find.byType(NewSupportRequestPage), findsOneWidget);
    expect(find.text('Sending…'), findsNothing);

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('support-subject')),
        matching: find.byType(TextField),
      ),
      'Gate lock not working',
    );
    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('support-message')),
        matching: find.byType(TextField),
      ),
      'Too short',
    );
    await tester.tap(find.text('Submit request'));
    await tester.pump();
    expect(find.text('Sending…'), findsNothing);

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('support-message')),
        matching: find.byType(TextField),
      ),
      'The building gate lock at Marina Gate 2 stopped working this morning.',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('support-submit')));
    await tester.pump();
    expect(find.text('Sending…'), findsOneWidget);
    await tester.pump(supportSendHold);
    expect(find.text('Request sent'), findsOneWidget);
    await tester.pump(supportToastHold);
    await tester.pumpAndSettle();

    expect(find.byType(HelpSupportPage), findsOneWidget);
    expect(find.text('Gate lock not working'), findsOneWidget);
    expect(find.textContaining('awaiting reply'), findsWidgets);
  });

  testWidgets('already rated matches BLUE submitted rating', (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final booking = Booking.fromJson(
      fakeBooking(
        uuid: 'b-rated',
        number: 'BKG-8841',
        status: 'COMPLETED',
        service: 'AC Deep Cleaning',
        startsAt: DateTime(2026, 8, 24, 10),
        completedAt: DateTime(2026, 8, 24, 12),
        quantity: 2,
        rating: {
          'value': 5,
          'comment':
              'The technician arrived on time and cleaned both units properly. Left everything tidy.',
          'submitted_at': '2026-08-24T12:00:00.000',
        },
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: buildBlueTheme(),
        home: AlreadyRatedPage(view: AlreadyRatedView.fromBooking(booking)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(AlreadyRatedPage), findsOneWidget);
    expect(find.byType(RatingBackButton), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(AlreadyRatedPage),
        matching: find.text('Completed'),
      ),
      findsOneWidget,
    );
    expect(find.text('AC Deep Cleaning'), findsOneWidget);
    expect(find.textContaining('BKG-8841'), findsOneWidget);
    expect(find.textContaining('24 Aug 2026'), findsWidgets);
    expect(find.textContaining('2 units'), findsOneWidget);
    expect(find.text('YOUR RATING'), findsOneWidget);
    expect(find.text('Excellent'), findsOneWidget);
    expect(find.text('Submitted 24 Aug 2026'), findsOneWidget);
    expect(
      find.text(
        'The technician arrived on time and cleaned both units properly. Left everything tidy.',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        "Ratings can't be changed once submitted. If something is wrong, open a support request.",
      ),
      findsOneWidget,
    );
    expect(find.byType(RatedStarsRow), findsOneWidget);
  });

  testWidgets('completed booking opens BLUE already rated', (tester) async {
    await pumpApp(
      tester,
      api: FakeBlueApi(
        bookings: [
          fakeBooking(
            uuid: 'b-rated',
            number: 'BKG-8841',
            status: 'COMPLETED',
            service: 'AC Deep Cleaning',
            startsAt: DateTime(2026, 8, 24, 10),
            completedAt: DateTime(2026, 8, 24, 12),
            quantity: 2,
            rating: {
              'value': 5,
              'comment':
                  'The technician arrived on time and cleaned both units properly. Left everything tidy.',
              'submitted_at': '2026-08-24T12:00:00.000',
            },
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Bookings'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Past'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('AC Deep Cleaning'));
    await tester.pumpAndSettle();
    expect(find.byType(BookingDetailPage), findsOneWidget);

    await tester.scrollUntilVisible(
      find.text('See your rating'),
      240,
      scrollable: find.descendant(
        of: find.byType(BookingDetailPage),
        matching: find.byWidgetPredicate(
          (widget) => widget is Scrollable && widget.axis == Axis.vertical,
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('See your rating'));
    await tester.pumpAndSettle();

    expect(find.byType(AlreadyRatedPage), findsOneWidget);
    expect(find.text('YOUR RATING'), findsOneWidget);
    expect(find.text('Excellent'), findsOneWidget);
    expect(
      find.text(
        'The technician arrived on time and cleaned both units properly. Left everything tidy.',
      ),
      findsOneWidget,
    );

    await tester.tap(find.byType(RatingBackButton));
    await tester.pumpAndSettle();
    expect(find.byType(AlreadyRatedPage), findsNothing);
    expect(find.byType(BookingDetailPage), findsOneWidget);
  });

  testWidgets('booking detail ratings matches BLUE per-service ratings', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final booking = Booking.fromJson(_ratingsBooking());
    await tester.pumpWidget(
      MaterialApp(
        theme: buildBlueTheme(),
        home: BookingDetailRatingsPage(
          view: BookingDetailRatingsView.fromBooking(booking),
          bookingUuid: booking.uuid,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(BookingDetailRatingsPage), findsOneWidget);
    expect(find.byType(RatingBackButton), findsOneWidget);
    expect(find.byType(RatingGoldRule), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(RatingStatusBadge),
        matching: find.text('In progress'),
      ),
      findsOneWidget,
    );
    expect(find.text('Apartment · Dubai Marina'), findsOneWidget);
    expect(find.textContaining('BKG-8841'), findsOneWidget);
    expect(find.textContaining('24 Aug 2026'), findsOneWidget);
    expect(find.textContaining('10:00–13:00'), findsOneWidget);
    expect(find.text('SERVICES'), findsOneWidget);
    expect(find.text('AC Deep Cleaning'), findsOneWidget);
    expect(find.text('Completed · 2 units'), findsOneWidget);
    expect(find.text('Excellent'), findsOneWidget);
    expect(find.byType(RatedStarsRow), findsOneWidget);
    expect(find.text('Plumbing repair'), findsOneWidget);
    expect(find.text('Completed'), findsOneWidget);
    expect(find.byType(RateServiceChip), findsOneWidget);
    expect(find.text('Rate service'), findsOneWidget);
    expect(find.text('Interior painting'), findsOneWidget);
    expect(
      find.text("In progress · you can rate it once it's finished"),
      findsOneWidget,
    );
    expect(
      find.text("Each service is rated on its own, whenever you're ready."),
      findsOneWidget,
    );
  });

  testWidgets('booking detail ratings shows a submitted comment', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final booking = Booking.fromJson(
      _ratingsBooking(
        firstRating: {
          'value': 5,
          'comment':
              'The technician arrived on time and cleaned both units properly.',
        },
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: buildBlueTheme(),
        home: BookingDetailRatingsPage(
          view: BookingDetailRatingsView.fromBooking(booking),
          bookingUuid: booking.uuid,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      find.text(
        'The technician arrived on time and cleaned both units properly.',
      ),
      findsOneWidget,
    );
    expect(find.byType(RatingQuote), findsOneWidget);
  });

  testWidgets('completed booking opens BLUE booking detail ratings', (
    tester,
  ) async {
    await pumpApp(
      tester,
      api: FakeBlueApi(
        bookings: [
          fakeBooking(
            uuid: 'b-rate',
            number: 'BKG-8841',
            status: 'COMPLETED',
            service: 'AC Deep Cleaning',
            startsAt: DateTime(2026, 8, 24, 10),
            endsAt: DateTime(2026, 8, 24, 13),
            completedAt: DateTime(2026, 8, 24, 12),
            quantity: 2,
            canRate: true,
            propertyType: 'Apartment',
            extraServices: const ['Plumbing repair'],
          ),
        ],
      ),
    );
    await _signIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(BlueBottomNav),
        matching: find.text('Bookings'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(BookingsPage),
        matching: find.text('Past'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('AC Deep Cleaning'));
    await tester.pumpAndSettle();
    expect(find.byType(BookingDetailPage), findsOneWidget);

    await tester.scrollUntilVisible(
      find.text('Rate your service'),
      240,
      scrollable: find.descendant(
        of: find.byType(BookingDetailPage),
        matching: find.byWidgetPredicate(
          (widget) => widget is Scrollable && widget.axis == Axis.vertical,
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rate your service'));
    await tester.pumpAndSettle();

    expect(find.byType(BookingDetailRatingsPage), findsOneWidget);
    expect(find.text('SERVICES'), findsOneWidget);
    expect(find.text('Rate service'), findsWidgets);
    expect(
      find.text("Each service is rated on its own, whenever you're ready."),
      findsOneWidget,
    );

    await tester.tap(find.byType(RatingBackButton));
    await tester.pumpAndSettle();
    expect(find.byType(BookingDetailRatingsPage), findsNothing);
    expect(find.byType(BookingDetailPage), findsOneWidget);
  });

  testWidgets('rate service sheet matches BLUE', (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final booking = Booking.fromJson(
      fakeBooking(
        uuid: 'b-rate-sheet',
        number: 'BKG-8841',
        status: 'COMPLETED',
        service: 'AC Deep Cleaning',
        startsAt: DateTime(2026, 8, 24, 10),
        endsAt: DateTime(2026, 8, 24, 13),
        completedAt: DateTime(2026, 8, 24, 12),
        quantity: 2,
        canRate: true,
        propertyType: 'Apartment',
      ),
    );
    await tester.pumpWidget(
      AppScope(
        dependencies: buildTestDependencies(),
        child: MaterialApp(
          theme: buildBlueTheme(),
          home: BookingDetailRatingsPage(
            view: BookingDetailRatingsView.fromBooking(booking),
            bookingUuid: booking.uuid,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rate service'));
    await tester.pumpAndSettle();

    expect(find.byType(RateServiceSheet), findsOneWidget);
    expect(find.text('How was your service?'), findsOneWidget);
    expect(find.text('AC Deep Cleaning · 24 Aug 2026'), findsOneWidget);
    expect(find.byType(RateStarButton), findsNWidgets(5));
    expect(find.text('Tap a star to rate'), findsOneWidget);
    expect(find.text('Tell us more'), findsOneWidget);
    expect(find.text('OPTIONAL'), findsOneWidget);
    expect(find.text('Anything the team should know.'), findsOneWidget);
    expect(find.text('Submit rating'), findsOneWidget);
    expect(find.text('Choose a star rating to submit.'), findsOneWidget);

    await tester.tap(find.text('Submit rating'));
    await tester.pump();
    expect(find.text('Submitting…'), findsNothing);

    await tester.tap(find.byType(RateStarButton).at(2));
    await tester.pumpAndSettle();
    expect(find.text('3 / 5'), findsOneWidget);
    expect(find.text('Good'), findsOneWidget);
    expect(find.text('Tap a star to rate'), findsNothing);
    expect(find.text('Choose a star rating to submit.'), findsNothing);

    await tester.tap(find.byType(RateStarButton).at(3));
    await tester.pumpAndSettle();
    expect(find.text('4 / 5'), findsOneWidget);
    expect(find.text('Very good'), findsOneWidget);
    expect(find.text('Submit rating'), findsOneWidget);
    expect(find.text('Choose a star rating to submit.'), findsNothing);
    expect(find.text('Anything the team should know.'), findsOneWidget);

    await tester.tap(find.byType(RateStarButton).at(4));
    await tester.pumpAndSettle();
    expect(find.text('5 / 5'), findsOneWidget);
    expect(find.text('Excellent'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('rate-note')),
      'Left everything tidy.',
    );
    await tester.tap(find.text('Submit rating'));
    await tester.pump();
    expect(find.text('Submitting…'), findsOneWidget);
    await tester.pump(rateSubmitHold);
    await tester.pumpAndSettle();

    expect(find.byType(RateServiceSheet), findsNothing);
    expect(find.byType(RatingSubmittedSheet), findsOneWidget);
    expect(find.text('Thank you for your feedback.'), findsOneWidget);
    expect(
      find.text('Your rating is saved with this service.'),
      findsOneWidget,
    );
    expect(find.text('Back to booking'), findsOneWidget);

    await tester.tap(find.text('Back to booking'));
    await tester.pumpAndSettle();

    expect(find.byType(RatingSubmittedSheet), findsNothing);
    expect(find.byType(RateServiceChip), findsNothing);
    expect(find.text('Excellent'), findsOneWidget);
    expect(find.text('Left everything tidy.'), findsOneWidget);
    expect(find.byType(RatedStarsRow), findsOneWidget);
    expect(find.byType(RatingQuote), findsOneWidget);
  });

  testWidgets('rating submitted sheet matches BLUE', (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      MaterialApp(
        theme: buildBlueTheme(),
        home: Scaffold(
          body: Builder(
            builder: (context) {
              return Center(
                child: TextButton(
                  onPressed: () => showRatingSubmittedSheet(context: context),
                  child: const Text('Open'),
                ),
              );
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    expect(find.byType(RatingSubmittedSheet), findsOneWidget);
    expect(find.text('Thank you for your feedback.'), findsOneWidget);
    expect(
      find.text('Your rating is saved with this service.'),
      findsOneWidget,
    );
    expect(find.text('Back to booking'), findsOneWidget);

    await tester.tap(find.text('Back to booking'));
    await tester.pumpAndSettle();
    expect(find.byType(RatingSubmittedSheet), findsNothing);
  });

  testWidgets('rate service sheet selected state matches BLUE', (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final booking = Booking.fromJson(
      fakeBooking(
        uuid: 'b-rate-selected',
        number: 'BKG-8841',
        status: 'COMPLETED',
        service: 'AC Deep Cleaning',
        startsAt: DateTime(2026, 8, 24, 10),
        endsAt: DateTime(2026, 8, 24, 13),
        completedAt: DateTime(2026, 8, 24, 12),
        quantity: 2,
        canRate: true,
        propertyType: 'Apartment',
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: buildBlueTheme(),
        home: BookingDetailRatingsPage(
          view: BookingDetailRatingsView.fromBooking(booking),
          bookingUuid: booking.uuid,
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rate service'));
    await tester.pumpAndSettle();

    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    await tester.pumpAndSettle();

    expect(find.byType(RateServiceSheet), findsOneWidget);
    expect(find.text('How was your service?'), findsOneWidget);
    expect(find.text('AC Deep Cleaning · 24 Aug 2026'), findsOneWidget);
    expect(find.text('4 / 5'), findsOneWidget);
    expect(find.text('Very good'), findsOneWidget);
    expect(find.text('Tell us more'), findsOneWidget);
    expect(find.text('OPTIONAL'), findsOneWidget);
    expect(find.text('Anything the team should know.'), findsOneWidget);
    expect(find.text('Submit rating'), findsOneWidget);
    expect(find.text('Tap a star to rate'), findsNothing);
    expect(find.text('Choose a star rating to submit.'), findsNothing);
  });
}

Map<String, dynamic> _ratingsBooking({Map<String, dynamic>? firstRating}) {
  return fakeBooking(
    uuid: 'b-ratings',
    number: 'BKG-8841',
    status: 'IN_PROGRESS',
    service: 'AC Deep Cleaning',
    startsAt: DateTime(2026, 8, 24, 10),
    endsAt: DateTime(2026, 8, 24, 13),
    propertyType: 'Apartment',
    items: [
      fakeBookingItem(
        uuid: 'i-ac',
        name: 'AC Deep Cleaning',
        status: 'COMPLETED',
        quantity: 2,
        rating: firstRating ?? {'value': 5},
      ),
      fakeBookingItem(
        uuid: 'i-plumb',
        name: 'Plumbing repair',
        status: 'COMPLETED',
      ),
      fakeBookingItem(
        uuid: 'i-paint',
        name: 'Interior painting',
        status: 'IN_PROGRESS',
      ),
    ],
  );
}
