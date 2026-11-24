class ApiEndpoints {
  // Change this to your live domain or local emulator IP (10.0.2.2 for Android emulator, localhost for web/desktop)
  static const String defaultBaseUrl = 'https://digitask.digiinksolutions.com/api';
  static const String localBaseUrl = 'http://10.0.2.2/digiink-task-flow/api';

  static String baseUrl = defaultBaseUrl;

  // Auth
  static String get login => '$baseUrl/auth.php';

  // Admin
  static String get adminDashboard => '$baseUrl/admin/dashboard.php';
  static String get adminUsers => '$baseUrl/admin/users.php';
  static String get adminTasks => '$baseUrl/admin/tasks.php';
  static String get adminNotifications => '$baseUrl/admin/notifications.php';
}

