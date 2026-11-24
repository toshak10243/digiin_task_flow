import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../core/constants/app_colors.dart';
import '../../data/models/admin_stats_model.dart';
import '../../data/models/task_model.dart';
import '../../data/services/api_service.dart';
import '../../data/services/auth_service.dart';
import '../widgets/stat_card.dart';
import '../widgets/task_card.dart';
import 'admin_task_detail_screen.dart';

class AdminDashboardScreen extends StatefulWidget {
  const AdminDashboardScreen({super.key});

  @override
  State<AdminDashboardScreen> createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends State<AdminDashboardScreen> {
  bool _isLoading = true;
  DateTime _selectedDate = DateTime.now();

  AdminStatsModel? _systemStats;
  DailyStatsModel? _dailyStats;
  List<Map<String, dynamic>> _topBde = [];
  List<Map<String, dynamic>> _topTeam = [];
  List<TaskModel> _recentTasks = [];
  List<TaskModel> _dailyTasks = [];

  @override
  void initState() {
    super.initState();
    _loadDashboard();
  }

  Future<void> _loadDashboard() async {
    setState(() => _isLoading = true);
    final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
    final data = await ApiService().getAdminDashboard(date: dateStr);

    if (!mounted) return;
    setState(() {
      _systemStats = data['system_stats'];
      _dailyStats = data['daily_stats'];
      _topBde = data['top_bde'] ?? [];
      _topTeam = data['top_team'] ?? [];
      _recentTasks = data['recent_tasks'] ?? [];
      _dailyTasks = data['daily_tasks'] ?? [];
      _isLoading = false;
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime(2024),
      lastDate: DateTime(2030),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppColors.primary,
              onPrimary: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );

    if (picked != null && picked != _selectedDate) {
      setState(() => _selectedDate = picked);
      _loadDashboard();
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthService().currentUser;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'DigiInk Solutions',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, letterSpacing: -0.3),
            ),
            Text(
              'Admin: ${user?.name ?? "Administrator"}',
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.textMuted),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, color: AppColors.textSecondary),
            tooltip: 'Refresh Data',
            onPressed: _loadDashboard,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : RefreshIndicator(
              onRefresh: _loadDashboard,
              color: AppColors.primary,
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // System Headline Cards
                    _buildSystemOverview(),

                    const SizedBox(height: 24),

                    // Date-wise Daily Hub Card
                    _buildDailyArchiveCard(),

                    const SizedBox(height: 24),

                    // Top Performers Leaderboard
                    _buildLeaderboards(),

                    const SizedBox(height: 24),

                    // Recent Tasks Section
                    _buildRecentTasksSection(),

                    const SizedBox(height: 30),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _buildSystemOverview() {
    final stats = _systemStats;
    if (stats == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'GLOBAL OPERATIONS STATS',
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            color: AppColors.textSecondary,
            letterSpacing: 0.8,
          ),
        ),
        const SizedBox(height: 12),
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollExceptionScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 1.45,
          children: [
            StatCard(
              title: 'Total Tasks',
              value: '${stats.totalTasks}',
              subtitle: '${stats.completed} Completed',
              icon: Icons.assignment_outlined,
              accentColor: AppColors.primary,
            ),
            StatCard(
              title: 'In Progress',
              value: '${stats.inProgress}',
              subtitle: 'Active with team',
              icon: Icons.sync_rounded,
              accentColor: AppColors.info,
            ),
            StatCard(
              title: 'Rework Queue',
              value: '${stats.rework}',
              subtitle: 'Needs revision',
              icon: Icons.rotate_left_rounded,
              accentColor: AppColors.error,
            ),
            StatCard(
              title: 'Urgent Tasks',
              value: '${stats.urgent}',
              subtitle: 'Priority delivery',
              icon: Icons.bolt_rounded,
              accentColor: AppColors.warning,
            ),
          ],
        ),
        const SizedBox(height: 12),

        // Monthly Word Count Banner
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: AppColors.secondary,
            borderRadius: BorderRadius.circular(18),
            boxShadow: [
              BoxShadow(
                color: AppColors.secondary.withOpacity(0.2),
                blurRadius: 15,
                offset: const Offset(0, 6),
              ),
            ],
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'MONTHLY VOLUME',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      color: AppColors.infoLight,
                      letterSpacing: 0.8,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    NumberFormat('#,##,###').format(stats.monthlyWords),
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      color: Colors.white,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Total words logged this month',
                    style: TextStyle(fontSize: 11, color: AppColors.textMuted),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Icon(Icons.analytics_rounded, color: Colors.white, size: 28),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildDailyArchiveCard() {
    final daily = _dailyStats;
    final formattedDate = DateFormat('dd MMM yyyy').format(_selectedDate);
    final isToday = DateFormat('yyyy-MM-dd').format(_selectedDate) ==
        DateFormat('yyyy-MM-dd').format(DateTime.now());

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.02),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header Row
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  const Icon(Icons.calendar_today_rounded, size: 18, color: AppColors.primary),
                  const SizedBox(width: 8),
                  Text(
                    isToday ? 'Today\'s Task Hub' : 'Archive Hub',
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
                  ),
                ],
              ),
              InkWell(
                onTap: _pickDate,
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.primaryLight,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.primary.withOpacity(0.2)),
                  ),
                  child: Row(
                    children: [
                      Text(
                        formattedDate,
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.primary),
                      ),
                      const SizedBox(width: 4),
                      const Icon(Icons.expand_more_rounded, size: 14, color: AppColors.primary),
                    ],
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),

          // Daily Stats Row
          Row(
            children: [
              _buildDailyMetric('TOTAL', '${daily?.total ?? 0}', AppColors.textPrimary),
              _buildDailyMetric('COMPLETED', '${daily?.completed ?? 0}', AppColors.success),
              _buildDailyMetric('WORKING', '${daily?.working ?? 0}', AppColors.info),
              _buildDailyMetric('REWORK', '${daily?.rework ?? 0}', AppColors.error),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildDailyMetric(String label, String value, Color color) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 3),
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.surfaceMuted,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          children: [
            Text(
              label,
              style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: AppColors.textMuted),
            ),
            const SizedBox(height: 4),
            Text(
              value,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: color),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildLeaderboards() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'TOP PERFORMERS',
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            color: AppColors.textSecondary,
            letterSpacing: 0.8,
          ),
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Row(
                children: [
                  Icon(Icons.workspace_premium_rounded, size: 18, color: AppColors.warning),
                  SizedBox(width: 6),
                  Text('Top BDEs (Lead Acquisition)', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
                ],
              ),
              const SizedBox(height: 12),
              ..._topBde.map((b) => _buildPerformerRow(
                    b['name'] ?? b['username'],
                    '${b['total_tasks']} Tasks',
                    '${NumberFormat('#,###').format(b['words'] ?? 0)} words',
                    AppColors.roleBDE,
                  )),
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Divider(),
              ),
              const Row(
                children: [
                  Icon(Icons.stars_rounded, size: 18, color: AppColors.success),
                  SizedBox(width: 6),
                  Text('Top Operations Experts (Delivery)', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
                ],
              ),
              const SizedBox(height: 12),
              ..._topTeam.map((t) => _buildPerformerRow(
                    t['name'] ?? t['username'],
                    '${t['completed_tasks']} Done',
                    '${NumberFormat('#,###').format(t['words'] ?? 0)} words',
                    AppColors.roleTeam,
                  )),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildPerformerRow(String name, String tasks, String words, Color badgeColor) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          CircleAvatar(
            radius: 14,
            backgroundColor: badgeColor.withOpacity(0.12),
            child: Text(
              name.isNotEmpty ? name[0].toUpperCase() : '?',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: badgeColor),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              tasks,
              style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.textSecondary),
            ),
          ),
          const SizedBox(width: 8),
          Text(
            words,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: AppColors.primary),
          ),
        ],
      ),
    );
  }

  Widget _buildRecentTasksSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'RECENT TASKS',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                color: AppColors.textSecondary,
                letterSpacing: 0.8,
              ),
            ),
            Text(
              '${_recentTasks.length} items',
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.textMuted),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_recentTasks.isEmpty)
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.border),
            ),
            child: const Center(child: Text('No tasks found')),
          )
        else
          ListView.separated(
            shrinkWrap: true,
            physics: const NeverScrollableScrollExceptionScrollPhysics(),
            itemCount: _recentTasks.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (ctx, index) {
              final task = _recentTasks[index];
              return TaskCard(
                task: task,
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => AdminTaskDetailScreen(taskId: task.id),
                    ),
                  );
                },
              );
            },
          ),
      ],
    );
  }
}

