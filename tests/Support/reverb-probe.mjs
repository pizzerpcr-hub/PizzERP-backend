// Diagnostic client: deliberately never unsubscribes or reconnects.
import { createInterface } from 'node:readline';
const socket = new WebSocket('ws://127.0.0.1:18080/app/test-key?protocol=7&client=js&version=8.4.0');
socket.addEventListener('message', ({data}) => {
    const message = JSON.parse(data);
    if (message.event === 'pusher:ping') {
        socket.send(JSON.stringify({event:'pusher:pong',data:{}}));
        return;
    }
    console.log(JSON.stringify(message));
});
socket.addEventListener('error', () => { console.log('{"event":"probe.error"}'); process.exit(1); });
socket.addEventListener('close', () => { console.log('{"event":"probe.closed"}'); process.exit(0); });
createInterface({input:process.stdin}).on('line', line => socket.send(line));
setTimeout(() => process.exit(2), 20000).unref();
