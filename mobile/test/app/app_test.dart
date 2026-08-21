import 'package:blue/app/app.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets(
    'boots to Splash and navigates Splash -> Login placeholder -> '
    'Home placeholder -> back to Splash',
    (tester) async {
      // Bounded pumps throughout, never pumpAndSettle: the Splash
      // placeholder shows an indeterminate CircularProgressIndicator,
      // which animates forever and would make pumpAndSettle time out.
      await tester.pumpWidget(
        const ProviderScope(child: BlueApp()),
      );
      await tester.pump();

      expect(find.text('F1 placeholder - not final UI'), findsOneWidget);
      expect(find.text('Continue to login placeholder'), findsOneWidget);

      await tester.tap(find.text('Continue to login placeholder'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Login (placeholder)'), findsOneWidget);

      await tester.tap(find.text('Continue to home placeholder'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Home (placeholder)'), findsOneWidget);

      await tester.tap(find.text('Back to splash'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Login (placeholder)'), findsNothing);
      expect(find.text('Continue to login placeholder'), findsOneWidget);
    },
  );
}
