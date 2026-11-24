import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/core/constants/api_endpoints.dart';
import 'package:digiink_app/data/services/auth_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_notifications_screen.dart';
import 'package:digiink_app/presentation/screens/auth/login_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_create_task_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_dashboard_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_my_tasks_screen.dart';

class BdeMainScreen extends StatefulWidget {
  final int initialIndex;
  const BdeMainScreen({super.key, this.initialIndex = 0});

  @override
  State<BdeMainScreen> createState() => _BdeMainScreenState();
}

class _BdeMainScreenState extends State<BdeMainScreen> {
  late int _currentIndex;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;
  }

  void _onTabSelected(int index) {
    setState(() => _currentIndex = index);
  }

  Future<void> _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Logout Confirmation', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        content: const Text('Are you sure you want to log out of BDE portal?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: AppColors.textSecondary)),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.error),
            child: const Text('Logout', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await AuthService().logout();
      if (!mounted) return;
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    }
  }

  void _showServerSettings() {
    final controller = TextEditingController(text: ApiEndpoints.baseUrl);
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('API Base URL', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        content: TextField(
          controller: controller,
          decoration: const InputDecoration(hintText: 'https://digitask.digiinksolutions.com/api'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () {
              ApiEndpoints.baseUrl = controller.text.trim();
              Navigator.pop(ctx);
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('API URL set to: ${ApiEndpoints.baseUrl}')),
              );
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthService().currentUser;

    final pages = [
      BdeDashboardScreen(
        onCreateTask: () => _onTabSelected(1),
        onOpenNotifications: () => _onTabSelected(3),
      ),
      BdeCreateTaskScreen(
        onTaskCreated: () => _onTabSelected(2),
      ),
      BdeMyTasksScreen(
        onCreateTask: () => _onTabSelected(1),
      ),
      const AdminNotificationsScreen(),
    ];

    final titles = ['BDE Dashboard', 'Create Task', 'My Tasks', 'Notifications'];

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(
                color: const Color(0xFFD99321),
                borderRadius: BorderRadius.circular(6),
              ),
              child: const Icon(Icons.flash_on_rounded, size: 16, color: Colors.white),
            ),
            const SizedBox(width: 8),
            Text(
              titles[_currentIndex],
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
            ),
          ],
        ),
        backgroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined, color: AppColors.textSecondary, size: 20),
            tooltip: 'Server Settings',
            onPressed: _showServerSettings,
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(color: AppColors.border, height: 1),
        ),
      ),
      drawer: Drawer(
        backgroundColor: Colors.white,
        child: Column(
          children: [
            // Drawer Header
            UserAccountsDrawerHeader(
              decoration: const BoxDecoration(color: AppColors.surface),
              currentAccountPicture: CircleAvatar(
                backgroundColor: const Color(0xFFD99321),
                child: Text(
                  (user?.name.isNotEmpty == true ? user!.name[0] : 'B').toUpperCase(),
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: Colors.white),
                ),
              ),
              accountName: Text(
                user?.name ?? 'BDE Executive',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.textPrimary, fontSize: 15),
              ),
              accountEmail: Row(
                children: [
                  Text(
                    user?.email.isNotEmpty == true ? user!.email : '@${user?.username ?? 'bde'}',
                    style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFDF3E0),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: const Text(
                      'BDE',
                      style: TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: Color(0xFF92400E)),
                    ),
                  ),
                ],
              ),
            ),

            // Navigation Items
            ListTile(
              leading: const Icon(Icons.dashboard_outlined, color: AppColors.textSecondary),
              title: const Text('Dashboard', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              selected: _currentIndex == 0,
              selectedTileColor: AppColors.surface,
              onTap: () {
                Navigator.pop(context);
                _onTabSelected(0);
              },
            ),
            ListTile(
              leading: const Icon(Icons.add_circle_outline_rounded, color: AppColors.textSecondary),
              title: const Text('Create New Task', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              selected: _currentIndex == 1,
              selectedTileColor: AppColors.surface,
              onTap: () {
                Navigator.pop(context);
                _onTabSelected(1);
              },
            ),
            ListTile(
              leading: const Icon(Icons.assignment_outlined, color: AppColors.textSecondary),
              title: const Text('My Tasks', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              selected: _currentIndex == 2,
              selectedTileColor: AppColors.surface,
              onTap: () {
                Navigator.pop(context);
                _onTabSelected(2);
              },
            ),
            ListTile(
              leading: const Icon(Icons.notifications_none_rounded, color: AppColors.textSecondary),
              title: const Text('Notifications', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              selected: _currentIndex == 3,
              selectedTileColor: AppColors.surface,
              onTap: () {
                Navigator.pop(context);
                _onTabSelected(3);
              },
            ),

            const Spacer(),
            const Divider(color: AppColors.border),

            // Logout
            ListTile(
              leading: const Icon(Icons.logout_rounded, color: AppColors.error),
              title: const Text('Logout', style: TextStyle(color: AppColors.error, fontWeight: FontWeight.bold, fontSize: 13)),
              onTap: _handleLogout,
            ),
            const SizedBox(height: 12),
          ],
        ),
      ),
      body: IndexedStack(
        index: _currentIndex,
        children: pages,
      ),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: BottomNavigationBar(
          currentIndex: _currentIndex,
          onTap: _onTabSelected,
          type: BottomNavigationBarType.fixed,
          backgroundColor: Colors.white,
          selectedItemColor: const Color(0xFFD99321),
          unselectedItemColor: AppColors.textMuted,
          selectedFontSize: 11,
          unselectedFontSize: 11,
          elevation: 0,
          items: const [
            BottomNavigationBarItem(
              icon: Icon(Icons.dashboard_outlined),
              activeIcon: Icon(Icons.dashboard_rounded),
              label: 'Dashboard',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.add_circle_outline_rounded),
              activeIcon: Icon(Icons.add_circle_rounded),
              label: 'Create Task',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.assignment_outlined),
              activeIcon: Icon(Icons.assignment_rounded),
              label: 'My Tasks',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.notifications_none_rounded),
              activeIcon: Icon(Icons.notifications_rounded),
              label: 'Alerts',
            ),
          ],
        ),
      ),
    );
  }
}

