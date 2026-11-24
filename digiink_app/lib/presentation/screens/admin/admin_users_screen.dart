import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/user_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/presentation/widgets/user_card.dart';

class AdminUsersScreen extends StatefulWidget {
  const AdminUsersScreen({super.key});

  @override
  State<AdminUsersScreen> createState() => _AdminUsersScreenState();
}

class _AdminUsersScreenState extends State<AdminUsersScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final _searchController = TextEditingController();
  bool _isLoading = true;
  List<UserModel> _users = [];

  final List<Map<String, String>> _roleTabs = const [
    {'label': 'All Users', 'role': 'all'},
    {'label': 'Ops Team', 'role': 'operations_team'},
    {'label': 'Managers', 'role': 'operations_manager'},
    {'label': 'BDE', 'role': 'bde'},
    {'label': 'BDM', 'role': 'bdm'},
    {'label': 'QC Team', 'role': 'qc'},
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _roleTabs.length, vsync: this);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) {
        _fetchUsers();
      }
    });
    _fetchUsers();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _searchController.dispose();
    super.dispose();
  }

  String get _currentRole => _roleTabs[_tabController.index]['role']!;

  Future<void> _fetchUsers() async {
    setState(() => _isLoading = true);
    final users = await ApiService().getUsers(
      role: _currentRole,
      search: _searchController.text.trim(),
    );

    if (!mounted) return;
    setState(() {
      _users = users;
      _isLoading = false;
    });
  }

  Future<void> _handleToggleStatus(UserModel user, bool newActive) async {
    final statusStr = newActive ? 'active' : 'inactive';
    final success = await ApiService().toggleUserStatus(user.id, statusStr);

    if (success && mounted) {
      _fetchUsers();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('${user.name} is now $statusStr'),
          backgroundColor: newActive ? AppColors.success : AppColors.error,
          duration: const Duration(seconds: 2),
        ),
      );
    }
  }

  void _showAddUserBottomSheet() {
    final nameCtrl = TextEditingController();
    final usernameCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final passwordCtrl = TextEditingController();
    String selectedRole = 'operations_team';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Container(
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 24,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 24,
          ),
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Add New Team Member',
                      style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close_rounded, size: 20),
                      onPressed: () => Navigator.pop(ctx),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Name
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'Full Name',
                    prefixIcon: Icon(Icons.badge_outlined, size: 20),
                  ),
                ),
                const SizedBox(height: 12),

                // Username
                TextField(
                  controller: usernameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'Username (unique login ID)',
                    prefixIcon: Icon(Icons.alternate_email_rounded, size: 20),
                  ),
                ),
                const SizedBox(height: 12),

                // Email
                TextField(
                  controller: emailCtrl,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                    labelText: 'Email Address (optional)',
                    prefixIcon: Icon(Icons.email_outlined, size: 20),
                  ),
                ),
                const SizedBox(height: 12),

                // Password
                TextField(
                  controller: passwordCtrl,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'Password',
                    prefixIcon: Icon(Icons.lock_outline_rounded, size: 20),
                  ),
                ),
                const SizedBox(height: 12),

                // Role Dropdown
                DropdownButtonFormField<String>(
                  initialValue: selectedRole,
                  decoration: const InputDecoration(
                    labelText: 'System Role',
                    prefixIcon: Icon(Icons.security_rounded, size: 20),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'operations_team', child: Text('Operations Team (Expert)')),
                    DropdownMenuItem(value: 'operations_manager', child: Text('Operations Manager')),
                    DropdownMenuItem(value: 'bde', child: Text('BDE (Sales)')),
                    DropdownMenuItem(value: 'bdm', child: Text('BDM (Manager)')),
                    DropdownMenuItem(value: 'qc', child: Text('Quality Control (QC)')),
                    DropdownMenuItem(value: 'admin', child: Text('Super Admin')),
                  ],
                  onChanged: (val) {
                    if (val != null) setModalState(() => selectedRole = val);
                  },
                ),
                const SizedBox(height: 24),

                // Submit Button
                ElevatedButton(
                  onPressed: () async {
                    if (nameCtrl.text.trim().isEmpty ||
                        usernameCtrl.text.trim().isEmpty ||
                        passwordCtrl.text.trim().isEmpty) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Please fill all required fields')),
                      );
                      return;
                    }

                    Navigator.pop(ctx);
                    final res = await ApiService().createUser(
                      name: nameCtrl.text.trim(),
                      username: usernameCtrl.text.trim(),
                      email: emailCtrl.text.trim(),
                      password: passwordCtrl.text.trim(),
                      role: selectedRole,
                    );

                    if (mounted) {
                      _fetchUsers();
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(res['message'] ?? 'User created successfully'),
                          backgroundColor: res['success'] == true ? AppColors.success : AppColors.error,
                        ),
                      );
                    }
                  },
                  child: const Text('Create User Profile'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text(
          'User Management',
          style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _fetchUsers,
          ),
          const SizedBox(width: 8),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(48),
          child: Container(
            color: AppColors.surface,
            child: TabBar(
              controller: _tabController,
              isScrollable: true,
              tabAlignment: TabAlignment.start,
              labelColor: AppColors.primary,
              unselectedLabelColor: AppColors.textMuted,
              indicatorColor: AppColors.primary,
              indicatorWeight: 3,
              labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
              tabs: _roleTabs.map((t) => Tab(text: t['label'])).toList(),
            ),
          ),
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.person_add_rounded, size: 20),
        label: const Text('Add User', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
        onPressed: _showAddUserBottomSheet,
      ),
      body: Column(
        children: [
          // Search Field
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: TextField(
              controller: _searchController,
              onSubmitted: (_) => _fetchUsers(),
              decoration: InputDecoration(
                hintText: 'Search by name or username...',
                prefixIcon: const Icon(Icons.search_rounded, size: 20, color: AppColors.textMuted),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear_rounded, size: 18),
                        onPressed: () {
                          _searchController.clear();
                          _fetchUsers();
                        },
                      )
                    : null,
              ),
            ),
          ),

          // Users List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _users.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Container(
                              padding: const EdgeInsets.all(20),
                              decoration: BoxDecoration(
                                color: AppColors.surfaceMuted,
                                borderRadius: BorderRadius.circular(30),
                              ),
                              child: const Icon(Icons.people_outline_rounded, size: 44, color: AppColors.textMuted),
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'No Users in this Role',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _fetchUsers,
                        color: AppColors.primary,
                        child: ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 16, 16, 80),
                          itemCount: _users.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (ctx, index) {
                            final user = _users[index];
                            return UserCard(
                              user: user,
                              onStatusToggle: (val) => _handleToggleStatus(user, val),
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}

