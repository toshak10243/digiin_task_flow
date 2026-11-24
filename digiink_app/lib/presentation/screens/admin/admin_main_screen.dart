import 'package:flutter/material.dart';
import '../../core/constants/app_colors.dart';
import '../../data/services/auth_service.dart';
import '../auth/login_screen.dart';
import 'admin_dashboard_screen.dart';
import 'admin_tasks_screen.dart';
import 'admin_users_screen.dart';
import 'admin_reports_screen.dart';
import 'admin_notifications_screen.dart';

class AdminMainScreen extends StatefulWidget {
  const AdminMainScreen({super.key});

  @override
  State<AdminMainScreen> createState() => _AdminMainScreenState();
}

class _AdminMainScreenState extends State<AdminMainScreen> {
  int _currentIndex = 0;

  final List<Widget> _screens = const [
    AdminDashboardScreen(),
    AdminTasksScreen(),
    AdminUsersScreen(),
    AdminReportsScreen(),
    AdminNotificationsScreen(),
  ];

  void _onTabTapped(int index) {
    setState(() => _currentIndex = index);
  }

  void _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign Out', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
        content: const Text('Are you sure you want to log out of Admin Console?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.error),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Log Out'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await AuthService().logout();
      if (!mounted) return;
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthService().currentUser;

    return Scaffold(
      drawer: Drawer(
        backgroundColor: AppColors.surface,
        child: Column(
          children: [
            UserAccountsDrawerHeader(
              decoration: const BoxDecoration(color: AppColors.secondary),
              accountName: Text(
                user?.name ?? 'Super Administrator',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
              accountEmail: Text(user?.email ?? 'admin@digiink.com'),
              currentAccountPicture: CircleAvatar(
                backgroundColor: AppColors.primary,
                child: Text(
                  user?.name.isNotEmpty == true ? user!.name[0].toUpperCase() : 'A',
                  style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: Colors.white),
                ),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.dashboard_rounded, color: AppColors.primary),
              title: const Text('Dashboard', style: TextStyle(fontWeight: FontWeight.w600)),
              selected: _currentIndex == 0,
              onTap: () { Navigator.pop(context); _onTabTapped(0); },
            ),
            ListTile(
              leading: const Icon(Icons.assignment_rounded, color: AppColors.primary),
              title: const Text('All Tasks', style: TextStyle(fontWeight: FontWeight.w600)),
              selected: _currentIndex == 1,
              onTap: () { Navigator.pop(context); _onTabTapped(1); },
            ),
            ListTile(
              leading: const Icon(Icons.group_rounded, color: AppColors.primary),
              title: const Text('User Management', style: TextStyle(fontWeight: FontWeight.w600)),
              selected: _currentIndex == 2,
              onTap: () { Navigator.pop(context); _onTabTapped(2); },
            ),
            ListTile(
              leading: const Icon(Icons.analytics_rounded, color: AppColors.primary),
              title: const Text('Reports & Volumes', style: TextStyle(fontWeight: FontWeight.w600)),
              selected: _currentIndex == 3,
              onTap: () { Navigator.pop(context); _onTabTapped(3); },
            ),
            ListTile(
              leading: const Icon(Icons.notifications_rounded, color: AppColors.primary),
              title: const Text('System Alerts', style: TextStyle(fontWeight: FontWeight.w600)),
              selected: _currentIndex == 4,
              onTap: () { Navigator.pop(context); _onTabTapped(4); },
            ),
            const Spacer(),
            const Divider(),
            ListTile(
              leading: const Icon(Icons.logout_rounded, color: AppColors.error),
              title: const Text('Sign Out', style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.error)),
              onTap: _handleLogout,
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: _onTabTapped,
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.dashboard_outlined),
            activeIcon: Icon(Icons.dashboard_rounded),
            label: 'Dashboard',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.assignment_outlined),
            activeIcon: Icon(Icons.assignment_rounded),
            label: 'Tasks',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.group_outlined),
            activeIcon: Icon(Icons.group_rounded),
            label: 'Users',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.analytics_outlined),
            activeIcon: Icon(Icons.analytics_rounded),
            label: 'Reports',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.notifications_none_rounded),
            activeIcon: Icon(Icons.notifications_rounded),
            label: 'Alerts',
          ),
        ],
      ),
    );
  }
}

