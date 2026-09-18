import { createServer } from './server/httpServer.js';

const port = Number(process.env.PORT ?? 7881);
const server = createServer();

server.listen(port, () => {
  console.log(`torrent-client listening on :${port}`);
});
