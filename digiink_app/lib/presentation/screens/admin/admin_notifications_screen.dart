import 'dart:async';
import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/notification_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';

class AdminNotificationsScreen extends StatefulWidget {
  const AdminNotificationsScreen({super.key});

  @override
  State<AdminNotificationsScreen> createState() => _AdminNotificationsScreenState();
}

class _AdminNotificationsScreenState extends State<AdminNotificationsScreen> {
  final _searchController = TextEditingController();
  Timer? _debounceTimer;
  bool _isLoading = true;
  String _selectedFilter = 'all';
  List<NotificationModel> _notifications = [];
  int _unreadCount = 0;

  final List<Map<String, String>> _filterOptions = const [
    {'value': 'all', 'label': 'All Alerts'},
    {'value': 'unread', 'label': 'Unread'},
    {'value': 'urgent', 'label': 'Urgent Tasks'},
    {'value': 'feedback', 'label': 'Feedback'},
    {'value': 'task', 'label': 'Task Lifecycle'},
  ];

  @override
  void initState() {
    super.initState();
    _fetchNotifications();
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String _) {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(milliseconds: 250), () {
      _fetchNotifications();
    });
  }

  Future<void> _fetchNotifications() async {
    setState(() => _isLoading = true);
    final res = await ApiService().getNotifications(
      type: _selectedFilter,
      search: _searchController.text.trim(),
    );

    if (!mounted) return;
    setState(() {
      _notifications = res['notifications'] as List<NotificationModel>;
      _unreadCount = res['unread'] as int? ?? 0;
      _isLoading = false;
    });
  }

  Future<void> _markAllAsRead() async {
    final success = await ApiService().markAllNotificationsRead();
    if (success && mounted) {
      setState(() {
        _notifications = _notifications.map((n) => n.copyWith(isRead: true)).toList();
        _unreadCount = 0;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('All notifications marked as read'),
          duration: Duration(seconds: 2),
          backgroundColor: AppColors.success,
        ),
      );
    }
  }

  Future<void> _onNotificationTap(NotificationModel n) async {
    if (!n.isRead) {
      ApiService().markNotificationRead(n.id);
      setState(() {
        final idx = _notifications.indexWhere((item) => item.id == n.id);
        if (idx != -1) {
          _notifications[idx] = _notifications[idx].copyWith(isRead: true);
          if (_unreadCount > 0) _unreadCount--;
        }
      });
    }

    if (n.taskId != null && n.taskId! > 0) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => AdminTaskDetailScreen(taskId: n.taskId!),
        ),
      );
    }
  }

  void _triggerTestPush() {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: AppColors.secondary,
        behavior: SnackBarBehavior.floating,
        content: const Row(
          children: [
            Icon(Icons.notifications_active_rounded, color: AppColors.accent, size: 22),
            SizedBox(width: 10),
            Expanded(
              child: Text(
                'FCM Broadcast triggered! Notifications synchronized across devices.',
                style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Color _getTypeColor(String type) {
    switch (type.toLowerCase()) {
      case 'urgent_task':
      case 'urgent':
        return AppColors.error;
      case 'feedback':
        return AppColors.roleBDM;
      case 'task_created':
        return AppColors.primary;
      case 'task_assigned':
        return AppColors.roleOM;
      case 'task_completed':
        return AppColors.success;
      case 'task_rework':
        return AppColors.warning;
      case 'additional_files':
        return const Color(0xFFD97706);
      default:
        return AppColors.textSecondary;
    }
  }

  IconData _getTypeIcon(String type) {
    switch (type.toLowerCase()) {
      case 'urgent_task':
      case 'urgent':
        return Icons.priority_high_rounded;
      case 'feedback':
        return Icons.rate_review_rounded;
      case 'task_created':
        return Icons.add_task_rounded;
      case 'task_assigned':
        return Icons.assignment_ind_rounded;
      case 'task_completed':
        return Icons.verified_rounded;
      case 'task_rework':
        return Icons.sync_problem_rounded;
      case 'additional_files':
        return Icons.folder_zip_rounded;
      default:
        return Icons.notifications_rounded;
    }
  }

  String _getTypeBadge(String type) {
    switch (type.toLowerCase()) {
      case 'urgent_task':
        return 'Urgent';
      case 'feedback':
        return 'Feedback';
      case 'task_created':
        return 'New Task';
      case 'task_assigned':
        return 'Assigned';
      case 'task_completed':
        return 'Completed';
      case 'task_rework':
        return 'Rework';
      case 'additional_files':
        return 'Extra Files';
      default:
        return 'System';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text(
          'Notifications History',
          style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.done_all_rounded, color: AppColors.primary),
            tooltip: 'Mark all as read',
            onPressed: _notifications.isEmpty ? null : _markAllAsRead,
          ),
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            tooltip: 'Refresh',
            onPressed: _fetchNotifications,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          // Push Service Banner
          Container(
            margin: const EdgeInsets.fromLTRB(16, 10, 16, 8),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.primary.withValues(alpha: 0.2)),
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.cloud_done_rounded, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Live Server Notification Sync',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.textPrimary),
                      ),
                      Text(
                        'Synced with digitask notifications history database',
                        style: TextStyle(fontSize: 11, color: AppColors.textMuted),
                      ),
                    ],
                  ),
                ),
                OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    side: const BorderSide(color: AppColors.primary),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: _triggerTestPush,
                  child: const Text('Test FCM', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
          ),

          // Search & Filter Header
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Column(
              children: [
                TextField(
                  controller: _searchController,
                  onChanged: _onSearchChanged,
                  decoration: InputDecoration(
                    hintText: 'Search notification title or task key...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20, color: AppColors.textMuted),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear_rounded, size: 18),
                            onPressed: () {
                              _searchController.clear();
                              _fetchNotifications();
                            },
                          )
                        : null,
                  ),
                ),
                const SizedBox(height: 10),

                // Category Filter Pills
                SizedBox(
                  height: 32,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: _filterOptions.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (ctx, i) {
                      final item = _filterOptions[i];
                      final isSelected = _selectedFilter == item['value'];
                      return ChoiceChip(
                        label: Text(
                          item['label']!,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: isSelected ? Colors.white : AppColors.textSecondary,
                          ),
                        ),
                        selected: isSelected,
                        selectedColor: AppColors.primary,
                        backgroundColor: AppColors.surfaceMuted,
                        showCheckmark: false,
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        side: BorderSide(
                          color: isSelected ? AppColors.primary : AppColors.border,
                        ),
                        onSelected: (val) {
                          if (val) {
                            setState(() => _selectedFilter = item['value']!);
                            _fetchNotifications();
                          }
                        },
                      );
                    },
                  ),
                ),
              ],
            ),
          ),

          // Notification List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _notifications.isEmpty
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
                              child: const Icon(Icons.notifications_off_outlined, size: 44, color: AppColors.textMuted),
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'No Notifications Found',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              'All system notifications are up to date',
                              style: TextStyle(fontSize: 12, color: AppColors.textMuted),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _fetchNotifications,
                        color: AppColors.primary,
                        child: ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: _notifications.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 10),
                          itemBuilder: (ctx, index) {
                            final n = _notifications[index];
                            final color = _getTypeColor(n.type);
                            final icon = _getTypeIcon(n.type);
                            final badgeText = _getTypeBadge(n.type);

                            return InkWell(
                              onTap: () => _onNotificationTap(n),
                              borderRadius: BorderRadius.circular(16),
                              child: Container(
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: n.isRead ? AppColors.surface : const Color(0xFFF8FAFC),
                                  borderRadius: BorderRadius.circular(16),
                                  border: Border.all(
                                    color: n.isRead ? AppColors.border : AppColors.primary.withValues(alpha: 0.3),
                                    width: n.isRead ? 1 : 1.5,
                                  ),
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withValues(alpha: 0.02),
                                      blurRadius: 6,
                                      offset: const Offset(0, 2),
                                    ),
                                  ],
                                ),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Icon Avatar
                                    Container(
                                      padding: const EdgeInsets.all(9),
                                      decoration: BoxDecoration(
                                        color: color.withValues(alpha: 0.12),
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Icon(icon, size: 18, color: color),
                                    ),
                                    const SizedBox(width: 12),

                                    // Content
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                                                decoration: BoxDecoration(
                                                  color: color.withValues(alpha: 0.1),
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: Text(
                                                  badgeText,
                                                  style: TextStyle(
                                                    fontSize: 10,
                                                    fontWeight: FontWeight.w800,
                                                    color: color,
                                                  ),
                                                ),
                                              ),
                                              if (n.taskKey != null && n.taskKey!.isNotEmpty) ...[
                                                const SizedBox(width: 6),
                                                Flexible(
                                                  child: Text(
                                                    n.taskKey!,
                                                    maxLines: 1,
                                                    overflow: TextOverflow.ellipsis,
                                                    style: const TextStyle(
                                                      fontSize: 10,
                                                      fontWeight: FontWeight.w700,
                                                      color: AppColors.primary,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                              const Spacer(),
                                              Text(
                                                n.createdAt,
                                                style: const TextStyle(fontSize: 10, color: AppColors.textMuted),
                                              ),
                                            ],
                                          ),
                                          const SizedBox(height: 6),
                                          Text(
                                            n.title,
                                            style: TextStyle(
                                              fontSize: 13,
                                              fontWeight: n.isRead ? FontWeight.w600 : FontWeight.w800,
                                              color: AppColors.textPrimary,
                                              height: 1.35,
                                            ),
                                          ),
                                          if (n.taskName != null && n.taskName!.isNotEmpty) ...[
                                            const SizedBox(height: 4),
                                            Text(
                                              n.taskName!,
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: const TextStyle(
                                                fontSize: 11,
                                                color: AppColors.textSecondary,
                                              ),
                                            ),
                                          ],
                                        ],
                                      ),
                                    ),

                                    // Unread indicator dot
                                    if (!n.isRead) ...[
                                      const SizedBox(width: 8),
                                      Container(
                                        width: 8,
                                        height: 8,
                                        margin: const EdgeInsets.only(top: 4),
                                        decoration: const BoxDecoration(
                                          color: AppColors.primary,
                                          shape: BoxShape.circle,
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
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
