import 'package:flutter_stripe/flutter_stripe.dart';

import '../data/payment_models.dart';

class StripeCheckoutPayment {
  const StripeCheckoutPayment._();

  static Future<void> present({
    required PaymentAttempt attempt,
    required String merchantDisplayName,
  }) async {
    final publishableKey = attempt.publishableKey?.trim() ?? '';
    final clientSecret = attempt.clientSecret?.trim() ?? '';
    if (publishableKey.isEmpty || clientSecret.isEmpty) {
      throw StateError('Payment is missing Stripe configuration from the server.');
    }

    Stripe.publishableKey = publishableKey;
    await Stripe.instance.applySettings();
    await Stripe.instance.initPaymentSheet(
      paymentSheetParameters: SetupPaymentSheetParameters(
        paymentIntentClientSecret: clientSecret,
        merchantDisplayName: merchantDisplayName,
      ),
    );
    await Stripe.instance.presentPaymentSheet();
  }
}
