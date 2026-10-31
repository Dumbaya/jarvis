import { type RouteConfig, index, layout, route } from "@react-router/dev/routes";

export default [
  route("login", "routes/login.tsx"),
  route("register", "routes/register.tsx"),
  route("logout", "routes/logout.tsx"),
  layout("routes/app-layout.tsx", [
    index("routes/dashboard.tsx"),
    route("jarvis", "routes/jarvis.tsx"),
    route("memos", "routes/memos.tsx"),
  ]),
] satisfies RouteConfig;
