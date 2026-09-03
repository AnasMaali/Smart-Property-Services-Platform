import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/blue_primary_button.dart';
import '../../home/presentation/widgets/home_icons.dart';
import '../../home/presentation/widgets/home_sections.dart';
import '../../profile/presentation/profile_page.dart';
import '../../properties/presentation/my_properties_page.dart';
import '../../support/presentation/help_support_page.dart';
import 'delete_account_page.dart';
import 'widgets/delete_account_widgets.dart';

class AccountPage extends StatefulWidget {
  const AccountPage({super.key});

  @override
  State<AccountPage> createState() => _AccountPageState();
}

class _AccountPageState extends State<AccountPage> {
  bool _busy = false;

  Future<void> _logout() async {
    if (_busy) return;
    setState(() => _busy = true);
    AppScope.of(context).profile.clear();
    await AppScope.of(context).auth.logout();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final session = AppScope.of(context).auth.current;
    final profile = AppScope.of(context).profile.cached;
    final name = profile?.fullName ?? session?.fullName ?? '';
    final phone = profile?.phoneNumber ?? session?.phoneNumber ?? '';
    final email = profile?.email ?? session?.email ?? '';
    final first =
        profile?.firstName ??
        (name.trim().isEmpty
            ? 'there'
            : name.trim().split(RegExp(r'\s+')).first);

    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          offset: const Offset(0, 0.02),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              BlueDimens.homeGutter,
              12,
              BlueDimens.homeGutter,
              26,
            ),
            child: LayoutBuilder(
              builder: (context, constraints) {
                return SingleChildScrollView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(
                      minHeight: constraints.maxHeight,
                    ),
                    child: IntrinsicHeight(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const HomeHeader(hasAlerts: false, onBell: _noop),
                          HomeGreeting(
                            name: first,
                            place: profile?.placeLabel ?? '',
                            compact: true,
                          ),
                          const SizedBox(height: 22),
                          _AccountLink(
                            title: 'Profile',
                            subtitle: 'The details BLUE has about you.',
                            onPressed: () {
                              Navigator.of(context).push(
                                BluePageRoute<void>(
                                  builder: (_) => const ProfilePage(),
                                ),
                              );
                            },
                          ),
                          const SizedBox(height: 10),
                          _AccountLink(
                            title: 'My properties',
                            subtitle: 'Places you book services for',
                            onPressed: () {
                              Navigator.of(context).push(
                                BluePageRoute<void>(
                                  builder: (_) => const MyPropertiesPage(),
                                ),
                              );
                            },
                          ),
                          const SizedBox(height: 10),
                          _AccountLink(
                            title: 'Help & Support',
                            subtitle: "We'll come back to you here.",
                            onPressed: () {
                              Navigator.of(context).push(
                                BluePageRoute<void>(
                                  builder: (_) => const HelpSupportPage(),
                                ),
                              );
                            },
                          ),
                          const SizedBox(height: 10),
                          _AccountRow(label: 'Phone', value: phone),
                          const SizedBox(height: 10),
                          _AccountRow(label: 'Email', value: email),
                          const Spacer(),
                          DeleteAccountLink(
                            onPressed: () {
                              Navigator.of(context).push(
                                BluePageRoute<void>(
                                  builder: (_) => const DeleteAccountPage(),
                                ),
                              );
                            },
                          ),
                          const SizedBox(height: 10),
                          BluePrimaryButton(
                            label: _busy ? 'Signing out…' : 'Sign out',
                            busy: _busy,
                            onPressed: _logout,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ),
    );
  }

  static void _noop() {}
}

class _AccountLink extends StatelessWidget {
  const _AccountLink({
    required this.title,
    required this.subtitle,
    required this.onPressed,
  });

  final String title;
  final String subtitle;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(18, 16, 14, 16),
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: BlueColors.border),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.ink,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    subtitle,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.muted,
                    ),
                  ),
                ],
              ),
            ),
            const BlueGlyphIcon(
              BlueGlyph.chevronRight,
              size: 14,
              color: BlueColors.rowChevron,
              strokeWidth: 1.9,
            ),
          ],
        ),
      ),
    );
  }
}

class _AccountRow extends StatelessWidget {
  const _AccountRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: BlueColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value.isEmpty ? '—' : value,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 16,
              fontWeight: FontWeight.w600,
              color: BlueColors.ink,
            ),
          ),
        ],
      ),
    );
  }
}
