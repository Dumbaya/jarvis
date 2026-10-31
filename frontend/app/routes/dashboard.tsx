import { useLoaderData, useRouteLoaderData } from "react-router";

import { api, type Service, type User } from "~/lib/api";
import type { Route } from "./+types/dashboard";

export const meta: Route.MetaFunction = () => [{ title: "대시보드 | Jarvis" }];

export function clientLoader() {
  return api<Service[]>("/services");
}

export default function Dashboard() {
  const services = useLoaderData<typeof clientLoader>();
  const user = useRouteLoaderData("routes/app-layout") as User;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">안녕하세요, {user.name}님</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{user.email}</p>
      </div>

      <section>
        <h2 className="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
          연결된 서비스
        </h2>

        {services.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
            <p className="font-medium text-slate-700 dark:text-slate-200">아직 등록된 서비스가 없어요</p>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              세컨드 브레인, 일정/예약, 할 일 트래커가 추가되면 여기에 표시됩니다.
            </p>
          </div>
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {services.map((s) => (
              <li
                key={s.id}
                className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
              >
                <h3 className="font-semibold text-slate-900 dark:text-white">{s.name}</h3>
                {s.description && (
                  <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{s.description}</p>
                )}
                <p className="mt-3 text-xs text-slate-400">도구 {s.tools.length}개</p>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
