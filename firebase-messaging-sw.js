importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');

firebase.initializeApp({
    apiKey: "AIzaSyA86lgJM-Lxd1Rq6xID76rCvoz5AK0W_-c",
    authDomain: "munch-kenya.firebaseapp.com",
    projectId: "munch-kenya",
    storageBucket: "munch-kenya.appspot.com",
    messagingSenderId: "601610831691",
    appId: "1:601610831691:web:15ead97b3c891711b24ace",
    measurementId: "G-HLT9T37GC3"
});

const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function (payload) {
    return self.registration.showNotification(payload.data.title, {
        body: payload.data.body ? payload.data.body : '',
        icon: payload.data.icon ? payload.data.icon : ''
    });
});