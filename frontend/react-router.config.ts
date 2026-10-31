import type { Config } from "@react-router/dev/config";

export default {
  // SPA 모드: 토큰을 브라우저에 저장하므로 인증은 클라이언트에서 처리하고, Vercel에는 정적 파일로 배포한다.
  ssr: false,
} satisfies Config;
