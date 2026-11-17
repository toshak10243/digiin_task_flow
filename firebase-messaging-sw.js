// Firebase Messaging Service Worker for Digiink Task Flow
// Enables background desktop push notifications like WhatsApp Web

importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js');

const firebaseConfig = {
  apiKey: "AIzaSyBkUNRoPr30LQQZ-uJI7UPYbwvqBJqKwK4",
  authDomain: "digiink-taskflow.firebaseapp.com",
  projectId: "digiink-taskflow",
  storageBucket: "digiink-taskflow.firebasestorage.app",
  messagingSenderId: "104305634643",
  appId: "1:104305634643:web:b42bfb0776e641143dc5a6",
  measurementId: "G-X4TF1B7PV9"
};

firebase.initializeApp(firebaseConfig);

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {
  console.log('[firebase-messaging-sw.js] Received background message ', payload);

  const title = (payload.notification && payload.notification.title) || (payload.data && payload.data.title) || "Digiink Alert";
  const body  = (payload.notification && payload.notification.body)  || (payload.data && payload.data.body)  || "You have a new task update.";
  const clickAction = (payload.data && payload.data.url) || (payload.fcmOptions && payload.fcmOptions.link) || "/";

  const options = {
    body: body,
    icon: "/favicon.png",
    badge: "/favicon.png",
    requireInteraction: true,
    vibrate: [200, 100, 200],
    data: {
      url: clickAction,
      taskId: payload.data && payload.data.task_id
    }
  };

  self.registration.showNotification(title, options);
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if ('focus' in client) {
          if (targetUrl && targetUrl !== '/') {
            client.navigate(targetUrl);
          }
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});

