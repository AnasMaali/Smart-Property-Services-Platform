import 'package:blue/app/app.dart';
import 'package:blue/features/home/presentation/home_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets(
    'boots to Splash and navigates Splash -> Welcome -> Login -> Register',
    (tester) async {
      // Bounded pumps throughout, never pumpAndSettle: Splash shows an
      // indeterminate CircularProgressIndicator (and several screens use
      // shimmering skeleton loaders) which animate forever and would make
      // pumpAndSettle time out.
      await tester.pumpWidget(const ProviderScope(child: BlueApp()));
      await tester.pump();

      expect(find.text('BLUE'), findsOneWidget);

      // Splash auto-navigates to Welcome after a short, fixed delay.
      await tester.pump(const Duration(milliseconds: 950));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Get started'), findsOneWidget);

      await tester.tap(find.text('I already have an account'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(
        find.text('Enter your phone number and password to continue.'),
        findsOneWidget,
      );

      await tester.tap(find.text('Register'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Your details'), findsOneWidget);
    },
  );

  testWidgets('Home shows the service category list', (tester) async {
    await tester.pumpWidget(
      const ProviderScope(child: MaterialApp(home: HomeScreen())),
    );
    await tester.pump();

    expect(find.text('Browse services'), findsOneWidget);
    expect(find.text('AC'), findsOneWidget);
    expect(find.text('Cleaning'), findsOneWidget);
  });
}
