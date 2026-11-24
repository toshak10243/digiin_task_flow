import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/admin_stats_model.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/data/services/auth_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';
import 'package:digiink_app/presentation/screens/admin/admin_tasks_screen.dart';

class AdminDashboardScreen extends StatefulWidget {
  const AdminDashboardScreen({super.key});

  @override
  State<AdminDashboardScreen> createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends State<AdminDashboardScreen> {
  bool _isLoading = true;
  DateTime _selectedDate = DateTime.now();
  int _leaderboardTab = 0; // 0: Top BDEs, 1: Top Ops Team

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

  String formatNumber(dynamic value) {
    if (value == null) return '0';
    if (value is num) {
      return NumberFormat('#,###').format(value);
    }
    final parsed = num.tryParse(value.toString()) ?? 0;
    return NumberFormat('#,###').format(parsed);
  }

  int parseInt(dynamic value) {
    if (value == null) return 0;
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value.toString()) ?? 0;
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
              surface: Colors.white,
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

  void _setDateToday() {
    final now = DateTime.now();
    if (_selectedDate.year != now.year ||
        _selectedDate.month != now.month ||
        _selectedDate.day != now.day) {
      setState(() => _selectedDate = now);
      _loadDashboard();
    }
  }

  void _setDateYesterday() {
    final yesterday = DateTime.now().subtract(const Duration(days: 1));
    setState(() => _selectedDate = yesterday);
    _loadDashboard();
  }

  bool get _isToday {
    final now = DateTime.now();
    return _selectedDate.year == now.year &&
        _selectedDate.month == now.month &&
        _selectedDate.day == now.day;
  }

  bool get _isYesterday {
    final y = DateTime.now().subtract(const Duration(days: 1));
    return _selectedDate.year == y.year &&
        _selectedDate.month == y.month &&
        _selectedDate.day == y.day;
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthService().currentUser;

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _loadDashboard,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // 1. Profile Greeting Header
                _buildHeader(user?.name ?? user?.username ?? 'Admin'),
                const SizedBox(height: 16),

                if (_isLoading) ...[
                  const SizedBox(height: 80),
                  const Center(child: CircularProgressIndicator(color: AppColors.primary)),
                  const SizedBox(height: 80),
                ] else ...[
                  // 2. Word Volume Hero Banner
                  _buildWordHeroBanner(),
                  const SizedBox(height: 14),

                  // 3. Operational 4-Grid Cards
                  _buildOperationalCards(),
                  const SizedBox(height: 18),

                  // 4. Daily Operations Log (Date-Wise Section)
                  _buildDailyOperationsCard(),
                  const SizedBox(height: 18),

                  // 5. Performance Leaderboards (Tabbed BDE vs Team)
                  _buildLeaderboardsCard(),
                  const SizedBox(height: 18),

                  // 6. Recent System Activity Feed
                  _buildRecentTasksFeed(),
                  const SizedBox(height: 24),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ─── 1. GREETING HEADER ──────────────────────────────────────────────────
  Widget _buildHeader(String name) {
    final initial = name.isNotEmpty ? name[0].toUpperCase() : 'A';
    final nowFormatted = DateFormat('EEE, dd MMM yyyy').format(DateTime.now());

    return Row(
      children: [
        CircleAvatar(
          radius: 20,
          backgroundColor: AppColors.primary,
          child: Text(
            initial,
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Welcome, $name',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF0F172A),
                  letterSpacing: -0.3,
                ),
              ),
              Text(
                nowFormatted,
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF64748B)),
              ),
            ],
          ),
        ),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            color: const Color(0xFFECFDF5),
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: const Color(0xFFA7F3D0)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: const [
              CircleAvatar(radius: 3.5, backgroundColor: Color(0xFF10B981)),
              SizedBox(width: 5),
              Text(
                'ONLINE',
                style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF065F46)),
              ),
            ],
          ),
        ),
      ],
    );
  }

  // ─── 2. WORD VOLUME HERO BANNER ──────────────────────────────────────────
  Widget _buildWordHeroBanner() {
    final stats = _systemStats;
    final totalWords = formatNumber(stats?.totalWords ?? 0);
    final monthWords = formatNumber(stats?.monthlyWords ?? 0);
    final currMonth = DateFormat('MMMM').format(DateTime.now());

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1E1B4B), Color(0xFF3730A3)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF3730A3).withValues(alpha: 0.25),
            blurRadius: 16,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(Icons.analytics_rounded, color: Colors.white, size: 16),
              ),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                  'SYSTEM WORD PRODUCTION',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                    color: Color(0xFFC7D2FE),
                    letterSpacing: 1.0,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  currMonth,
                  style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            '$totalWords Words',
            style: const TextStyle(
              fontSize: 26,
              fontWeight: FontWeight.w900,
              color: Colors.white,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            '$monthWords words processed in $currMonth',
            style: const TextStyle(fontSize: 12, color: Color(0xFFE0E7FF), fontWeight: FontWeight.w500),
          ),
        ],
      ),
    );
  }

  // ─── 3. OPERATIONAL 4-GRID CARDS ─────────────────────────────────────────
  Widget _buildOperationalCards() {
    final stats = _systemStats;

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _buildMetricTile(
                title: 'Total Tasks',
                count: '${stats?.totalTasks ?? 0}',
                subtitle: 'All-time volume',
                icon: Icons.assignment_outlined,
                color: const Color(0xFF2563EB),
                bgTint: const Color(0xFFEFF6FF),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _buildMetricTile(
                title: 'In Progress',
                count: '${stats?.inProgress ?? 0}',
                subtitle: 'Active with team',
                icon: Icons.pending_actions_outlined,
                color: const Color(0xFFD97706),
                bgTint: const Color(0xFFFFFBEB),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _buildMetricTile(
                title: 'Completed',
                count: '${stats?.completed ?? 0}',
                subtitle: 'Delivered tasks',
                icon: Icons.check_circle_outline,
                color: const Color(0xFF16A34A),
                bgTint: const Color(0xFFECFDF5),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _buildMetricTile(
                title: 'Urgent Tasks',
                count: '${stats?.urgent ?? 0}',
                subtitle: 'Priority queue',
                icon: Icons.priority_high_rounded,
                color: const Color(0xFFDC2626),
                bgTint: const Color(0xFFFEF2F2),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),

        // Workforce pill
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: const Color(0xFFEEF2F6)),
          ),
          child: Row(
            children: [
              const Icon(Icons.people_alt_outlined, color: AppColors.primary, size: 18),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  '${stats?.totalUsers ?? 0} Team Members active across 5 operational tiers',
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF475569)),
                ),
              ),
              const Icon(Icons.chevron_right_rounded, size: 18, color: Color(0xFF94A3B8)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildMetricTile({
    required String title,
    required String count,
    required String subtitle,
    required IconData icon,
    required Color color,
    required Color bgTint,
  }) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x04000000), blurRadius: 8, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                padding: const EdgeInsets.all(7),
                decoration: BoxDecoration(
                  color: bgTint,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(icon, color: color, size: 16),
              ),
              Text(
                count,
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                  color: color,
                  letterSpacing: -0.5,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: Color(0xFF1E293B)),
          ),
          Text(
            subtitle,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 10, color: Color(0xFF94A3B8), fontWeight: FontWeight.w500),
          ),
        ],
      ),
    );
  }

  // ─── 4. DAILY OPERATIONS LOG ─────────────────────────────────────────────
  Widget _buildDailyOperationsCard() {
    final daily = _dailyStats;
    final dateDisplay = DateFormat('dd MMM yyyy').format(_selectedDate);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Title & Date Controls
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(7),
                decoration: BoxDecoration(
                  color: const Color(0xFF0F172A),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.calendar_month_outlined, color: Colors.white, size: 16),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Text(
                          'Daily Operations Log',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Color(0xFF0F172A)),
                        ),
                        if (_isToday) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                            decoration: BoxDecoration(
                              color: const Color(0xFFD1FAE5),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: const Text(
                              'TODAY',
                              style: TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: Color(0xFF065F46)),
                            ),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      dateDisplay,
                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: Color(0xFF94A3B8)),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Date Chips Row
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _buildDateChip('Today', _isToday, _setDateToday),
                const SizedBox(width: 6),
                _buildDateChip('Yesterday', _isYesterday, _setDateYesterday),
                const SizedBox(width: 6),
                InkWell(
                  onTap: _pickDate,
                  borderRadius: BorderRadius.circular(10),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF1F5F9),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFFE2E8F0)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: const [
                        Icon(Icons.date_range_outlined, size: 12, color: Color(0xFF475569)),
                        SizedBox(width: 4),
                        Text('Calendar', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF475569))),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),

          // 4 Responsive Metrics in a Row (overflow-proof)
          Row(
            children: [
              Expanded(child: _buildDailySmallTile('Booked', '${daily?.total ?? 0}', const Color(0xFF0F172A))),
              const SizedBox(width: 6),
              Expanded(child: _buildDailySmallTile('Active', '${daily?.working ?? 0}', const Color(0xFFD97706))),
              const SizedBox(width: 6),
              Expanded(child: _buildDailySmallTile('Done', '${daily?.completed ?? 0}', const Color(0xFF16A34A))),
              const SizedBox(width: 6),
              Expanded(child: _buildDailySmallTile('Words', formatNumber(daily?.words ?? 0), const Color(0xFF4F46E5))),
            ],
          ),
          const SizedBox(height: 12),

          // Daily Tasks List
          if (_dailyTasks.isEmpty)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 20),
              alignment: Alignment.center,
              child: const Column(
                children: [
                  Icon(Icons.event_busy_outlined, color: Color(0xFFCBD5E1), size: 28),
                  SizedBox(height: 4),
                  Text(
                    'No tasks recorded for this date',
                    style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8), fontWeight: FontWeight.w600),
                  ),
                ],
              ),
            )
          else ...[
            ..._dailyTasks.take(4).map((t) => _buildDailyTaskRow(t)),
            if (_dailyTasks.length > 4) ...[
              const SizedBox(height: 4),
              Center(
                child: TextButton(
                  onPressed: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const AdminTasksScreen()),
                    );
                  },
                  child: Text(
                    'View all ${_dailyTasks.length} tasks for this date →',
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.primary),
                  ),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _buildDateChip(String label, bool isSelected, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFF0F172A) : const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w700,
            color: isSelected ? Colors.white : const Color(0xFF475569),
          ),
        ),
      ),
    );
  }

  Widget _buildDailySmallTile(String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFEEF2F6)),
      ),
      child: Column(
        children: [
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w700, color: Color(0xFF94A3B8)),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900, color: color),
          ),
        ],
      ),
    );
  }

  Widget _buildDailyTaskRow(TaskModel task) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFEEF2F6)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
            decoration: BoxDecoration(
              color: const Color(0xFFE2E8F0),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              task.taskKey.length >= 3 ? task.taskKey.substring(task.taskKey.length - 3) : task.taskKey,
              style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w900, color: Color(0xFF0F172A)),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  task.taskName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF1E293B)),
                ),
                Text(
                  '${formatNumber(task.wordCount)} words • ${task.expertName ?? "Unassigned"}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 9, color: Color(0xFF94A3B8), fontWeight: FontWeight.w500),
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          _buildStatusPill(task.status),
        ],
      ),
    );
  }

  Widget _buildStatusPill(String status) {
    Color bg;
    Color fg;
    switch (status.toLowerCase()) {
      case 'completed':
        bg = const Color(0xFFECFDF5);
        fg = const Color(0xFF059669);
        break;
      case 'in_progress':
        bg = const Color(0xFFEFF6FF);
        fg = const Color(0xFF2563EB);
        break;
      case 'rework':
        bg = const Color(0xFFFEF2F2);
        fg = const Color(0xFFDC2626);
        break;
      default:
        bg = const Color(0xFFF1F5F9);
        fg = const Color(0xFF64748B);
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2.5),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
      child: Text(
        status.replaceAll('_', ' ').toUpperCase(),
        style: TextStyle(fontSize: 8, fontWeight: FontWeight.w800, color: fg),
      ),
    );
  }

  // ─── 5. PERFORMANCE LEADERBOARDS ─────────────────────────────────────────
  Widget _buildLeaderboardsCard() {
    final list = _leaderboardTab == 0 ? _topBde : _topTeam;
    final isBde = _leaderboardTab == 0;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.workspace_premium_outlined, color: AppColors.primary, size: 18),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                  'Production Leaderboards',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Color(0xFF0F172A)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Tabs
          Container(
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(
              color: const Color(0xFFF1F5F9),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Expanded(
                  child: InkWell(
                    onTap: () => setState(() => _leaderboardTab = 0),
                    borderRadius: BorderRadius.circular(10),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      decoration: BoxDecoration(
                        color: _leaderboardTab == 0 ? Colors.white : Colors.transparent,
                        borderRadius: BorderRadius.circular(10),
                        boxShadow: _leaderboardTab == 0
                            ? const [BoxShadow(color: Color(0x0A000000), blurRadius: 4, offset: Offset(0, 1))]
                            : null,
                      ),
                      alignment: Alignment.center,
                      child: Text(
                        'Top BDEs (Sales)',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: _leaderboardTab == 0 ? const Color(0xFF2563EB) : const Color(0xFF64748B),
                        ),
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: InkWell(
                    onTap: () => setState(() => _leaderboardTab = 1),
                    borderRadius: BorderRadius.circular(10),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      decoration: BoxDecoration(
                        color: _leaderboardTab == 1 ? Colors.white : Colors.transparent,
                        borderRadius: BorderRadius.circular(10),
                        boxShadow: _leaderboardTab == 1
                            ? const [BoxShadow(color: Color(0x0A000000), blurRadius: 4, offset: Offset(0, 1))]
                            : null,
                      ),
                      alignment: Alignment.center,
                      child: Text(
                        'Ops Team (Delivery)',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: _leaderboardTab == 1 ? const Color(0xFF16A34A) : const Color(0xFF64748B),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),

          if (list.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Center(
                child: Text('No performance data available yet', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
              ),
            )
          else ...[
            ...list.asMap().entries.map((entry) {
              final rank = entry.key + 1;
              final item = entry.value;
              final name = item['name'] ?? item['username'] ?? 'User';
              final count = isBde ? parseInt(item['total_tasks']) : parseInt(item['completed_tasks']);
              final countLabel = isBde ? '$count Tasks' : '$count Done';
              final words = formatNumber(item['words']);

              return Container(
                margin: const EdgeInsets.only(bottom: 6),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFFEEF2F6)),
                ),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 11,
                      backgroundColor: rank == 1
                          ? const Color(0xFFF59E0B)
                          : rank == 2
                              ? const Color(0xFF94A3B8)
                              : rank == 3
                                  ? const Color(0xFFB45309)
                                  : const Color(0xFFCBD5E1),
                      child: Text(
                        '$rank',
                        style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF1E293B)),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Text(
                      countLabel,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: isBde ? const Color(0xFF2563EB) : const Color(0xFF16A34A),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '$words w',
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: Color(0xFF4F46E5)),
                    ),
                  ],
                ),
              );
            }),
          ],
        ],
      ),
    );
  }

  // ─── 6. RECENT SYSTEM TASKS FEED ─────────────────────────────────────────
  Widget _buildRecentTasksFeed() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: const [
              Icon(Icons.history_rounded, size: 18, color: Color(0xFF64748B)),
              SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Recent System Activity',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Color(0xFF0F172A)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),

          if (_recentTasks.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(
                child: Text('No recent activity records', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
              ),
            )
          else
            ..._recentTasks.map((task) {
              return InkWell(
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => AdminTaskDetailScreen(taskId: task.id),
                    ),
                  );
                },
                child: Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFEEF2F6)),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Text(
                                  task.taskKey,
                                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: Color(0xFF0F172A)),
                                ),
                                const SizedBox(width: 6),
                                _buildStatusPill(task.status),
                              ],
                            ),
                            const SizedBox(height: 2),
                            Text(
                              task.taskName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontSize: 10, color: Color(0xFF64748B)),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            task.deadline.length >= 10 ? task.deadline.substring(0, 10) : task.deadline,
                            style: const TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: Color(0xFFEF4444)),
                          ),
                          const SizedBox(height: 2),
                          const Icon(Icons.arrow_forward_ios_rounded, size: 10, color: Color(0xFF94A3B8)),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            }),
        ],
      ),
    );
  }
}
