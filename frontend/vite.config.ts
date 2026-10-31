import dns from "node:dns";

import { reactRouter } from "@react-router/dev/vite";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

// SPA 모드 빌드는 index.html을 만들려고 임시 서버를 띄운 뒤 127.0.0.1로 요청한다. localhost가 IPv6(::1)로 먼저 풀리는
// 환경(Docker 컨테이너, 일부 CI)에서는 서버는 ::1에만 열리고 요청은 IPv4로 가서 "ECONNREFUSED"로 빌드가 실패한다.
// (로컬 Windows Node에서는 재현되지 않아서 CI를 깨끗한 환경에서 돌려 보기 전까지 몰랐다.)
dns.setDefaultResultOrder("ipv4first");

export default defineConfig({
  plugins: [tailwindcss(), reactRouter()],
  resolve: {
    tsconfigPaths: true,
  },
  server: {
    // 개발 중에는 같은 출처로 요청해서 CORS 없이 백엔드(Docker nginx)에 연결한다.
    proxy: { "/api": "http://localhost:8080" },
  },
});
