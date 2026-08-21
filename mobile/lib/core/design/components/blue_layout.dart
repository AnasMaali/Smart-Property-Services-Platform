import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_spacing.dart';

/// Standard page scaffold used by every screen in the app. Wraps
/// [Scaffold] so app bar presence, background color, and safe-area
/// handling are consistent everywhere - individual screens should never
/// build a bare [Scaffold] directly.
class AppScaffold extends StatelessWidget {
  const AppScaffold({
    super.key,
    required this.body,
    this.appBar,
    this.floatingActionButton,
    this.bottomBar,
    this.backgroundColor,
    this.resizeToAvoidBottomInset = true,
  });

  final Widget body;
  final PreferredSizeWidget? appBar;
  final Widget? floatingActionButton;

  /// A bottom-pinned element (e.g. a primary CTA bar) shown above the
  /// safe-area inset, distinct from the app's persistent bottom nav
  /// (which lives in the shell, not per-screen).
  final Widget? bottomBar;
  final Color? backgroundColor;
  final bool resizeToAvoidBottomInset;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: appBar,
      backgroundColor: backgroundColor ?? BlueColors.background,
      resizeToAvoidBottomInset: resizeToAvoidBottomInset,
      floatingActionButton: floatingActionButton,
      body: SafeArea(
        top: appBar == null,
        bottom: bottomBar == null,
        child: body,
      ),
      bottomNavigationBar: bottomBar == null
          ? null
          : SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(
                  BlueSpacing.pageGutter,
                  BlueSpacing.space12,
                  BlueSpacing.pageGutter,
                  BlueSpacing.space12,
                ),
                child: bottomBar,
              ),
            ),
    );
  }
}

/// Applies the standard horizontal page gutter (and optional vertical
/// padding) around a screen's content. Use inside a [ListView]/
/// [SingleChildScrollView] via [PageContainer.scrollable], or directly for
/// non-scrolling screens.
class PageContainer extends StatelessWidget {
  const PageContainer({
    super.key,
    required this.child,
    this.verticalPadding = BlueSpacing.space16,
  });

  final Widget child;
  final double verticalPadding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(
        horizontal: BlueSpacing.pageGutter,
        vertical: verticalPadding,
      ),
      child: child,
    );
  }
}

/// A titled group of content with the app's standard section spacing.
/// The building block for "Home", "Cart", "Checkout Review" etc. where a
/// screen is composed of several labeled sections.
class SectionContainer extends StatelessWidget {
  const SectionContainer({
    super.key,
    this.title,
    this.trailing,
    required this.child,
    this.padding = EdgeInsets.zero,
  });

  final String? title;
  final Widget? trailing;
  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title != null)
            Padding(
              padding: const EdgeInsets.only(bottom: BlueSpacing.space12),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      title!,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  ?trailing,
                ],
              ),
            ),
          child,
        ],
      ),
    );
  }
}

/// A thin horizontal rule used between grouped sections, when a visual
/// break is needed without a full section gap.
class BlueDivider extends StatelessWidget {
  const BlueDivider({super.key});

  @override
  Widget build(BuildContext context) {
    return const Divider(height: 1, thickness: 1);
  }
}
