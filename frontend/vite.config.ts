import { reactRouter } from "@react-router/dev/vite";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

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
