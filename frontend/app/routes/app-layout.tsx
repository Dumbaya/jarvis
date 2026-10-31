import { Form, NavLink, Outlet, useLoaderData } from "react-router";

import { requireUser } from "~/lib/api";

const navClass = ({ isActive }: { isActive: boolean }) =>
  isActive
    ? "font-medium text-indigo-600 dark:text-indigo-400"
    : "text-slate-500 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white";

export function clientLoader() {
  return requireUser();
}

export default function AppLayout() {
  const user = useLoaderData<typeof clientLoader>();

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
          <div className="flex items-center gap-6">
            <div className="flex items-center gap-2">
              <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">
                J
              </div>
              <span className="font-semibold text-slate-900 dark:text-white">Jarvis</span>
            </div>
            <nav className="flex items-center gap-4 text-sm">
              <NavLink to="/" end className={navClass}>
                대시보드
              </NavLink>
              <NavLink to="/jarvis" className={navClass}>
                자비스
              </NavLink>
              <NavLink to="/memos" className={navClass}>
                세컨드 브레인
              </NavLink>
            </nav>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm text-slate-600 dark:text-slate-300">{user.name}</span>
            <Form method="post" action="/logout">
              <button
                type="submit"
                className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                로그아웃
              </button>
            </Form>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">
        <Outlet />
      </main>
    </div>
  );
}
