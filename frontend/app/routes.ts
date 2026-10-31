import { type RouteConfig, index, layout, route } from "@react-router/dev/routes";

export default [
  route("login", "routes/login.tsx"),
  route("register", "routes/register.tsx"),
  route("logout", "routes/logout.tsx"),
  layout("routes/app-layout.tsx", [
    index("routes/dashboard.tsx"),
    route("jarvis", "routes/jarvis.tsx"),
    route("logs", "routes/logs.tsx"),
    route("memos", "routes/memos.tsx"),
    route("schedule", "routes/schedule.tsx"),
    route("tasks", "routes/tasks.tsx"),
    route("ledger", "routes/ledger.tsx"),
  ]),
] satisfies RouteConfig;
