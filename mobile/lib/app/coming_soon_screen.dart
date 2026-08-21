import 'package:flutter/material.dart';

import '../core/design/components/blue_feedback.dart';
import '../core/design/components/blue_layout.dart';

/// A temporary, honestly-labeled placeholder for a tab whose real screens
/// haven't been built yet in this phase (Cart/Checkout/Payment, Bookings,
/// Contracts, and Account/Profile/Properties/Settings are each their own
/// upcoming implementation phase per the blueprint's phase order). This
/// exists only so the full 5-tab navigation shell and information
/// architecture can be reviewed end-to-end today - it is not meant to be
/// mistaken for a finished screen, and every call site is expected to be
/// replaced, not extended.
class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({
    super.key,
    required this.title,
    required this.icon,
    required this.message,
  });

  final String title;
  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      appBar: AppBar(title: Text(title)),
      body: EmptyStateView(icon: icon, title: title, message: message),
    );
  }
}
