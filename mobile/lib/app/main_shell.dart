import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/design/tokens/blue_colors.dart';

/// The persistent 5-tab bottom navigation shell (blueprint §20 navigation
/// map): Home, Cart, Bookings, Contracts, Account. Chosen over a longer
/// tab bar because these five are the only product areas frequent/urgent
/// enough to deserve a permanent one-tap slot - Properties and Settings
/// live one level deeper, under Account, since they're configured
/// occasionally rather than used every session.
///
/// Built on [StatefulShellRoute.indexedStack] so each tab keeps its own
/// navigation stack and scroll position when switching away and back -
/// switching tabs is not the same as restarting that tab's flow.
class MainShell extends StatelessWidget {
  const MainShell({
    super.key,
    required this.navigationShell,
    this.cartItemCount = 0,
  });

  final StatefulNavigationShell navigationShell;

  /// Demo-only badge count for the Cart destination - a real build reads
  /// this from `CartNotifier` (blueprint §15), invalidated after every
  /// cart mutation.
  final int cartItemCount;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: navigationShell.currentIndex,
        onDestinationSelected: (index) => navigationShell.goBranch(
          index,
          initialLocation: index == navigationShell.currentIndex,
        ),
        destinations: [
          const NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home_rounded),
            label: 'Home',
          ),
          NavigationDestination(
            icon: _badged(
              const Icon(Icons.shopping_bag_outlined),
              cartItemCount,
            ),
            selectedIcon: _badged(
              const Icon(Icons.shopping_bag_rounded),
              cartItemCount,
            ),
            label: 'Cart',
          ),
          const NavigationDestination(
            icon: Icon(Icons.event_note_outlined),
            selectedIcon: Icon(Icons.event_note_rounded),
            label: 'Bookings',
          ),
          const NavigationDestination(
            icon: Icon(Icons.verified_user_outlined),
            selectedIcon: Icon(Icons.verified_user_rounded),
            label: 'Contracts',
          ),
          const NavigationDestination(
            icon: Icon(Icons.person_outline_rounded),
            selectedIcon: Icon(Icons.person_rounded),
            label: 'Account',
          ),
        ],
      ),
    );
  }

  Widget _badged(Widget icon, int count) {
    if (count <= 0) return icon;
    return Badge(
      backgroundColor: BlueColors.error,
      label: Text('$count'),
      child: icon,
    );
  }
}
