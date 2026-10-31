import { Form, Link, useActionData, useNavigation } from "react-router";

type Field = {
  name: string;
  label: string;
  type?: string;
  autoComplete?: string;
};

type ActionResult = { error?: string; errors?: Record<string, string[]> } | undefined;

export function AuthForm({
  title,
  subtitle,
  fields,
  submitLabel,
  footer,
}: {
  title: string;
  subtitle: string;
  fields: Field[];
  submitLabel: string;
  footer: { text: string; linkLabel: string; to: string };
}) {
  const result = useActionData() as ActionResult;
  const submitting = useNavigation().state === "submitting";
  const fieldErrors = result?.errors ?? {};
  // 필드별 오류가 있으면 그쪽에 보여 주고, 없을 때만 상단에 공통 오류를 보여 준다.
  const topError = result?.error && Object.keys(fieldErrors).length === 0 ? result.error : null;

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12 dark:bg-slate-950">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">
            J
          </div>
          <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">{title}</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>
        </div>

        <Form
          method="post"
          className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"
        >
          {topError && (
            <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
              {topError}
            </p>
          )}

          {fields.map((f) => {
            const error = fieldErrors[f.name]?.[0];
            return (
              <div key={f.name}>
                <label htmlFor={f.name} className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
                  {f.label}
                </label>
                <input
                  id={f.name}
                  name={f.name}
                  type={f.type ?? "text"}
                  autoComplete={f.autoComplete}
                  required
                  aria-invalid={error ? true : undefined}
                  className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 aria-[invalid=true]:border-red-400 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                />
                {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
              </div>
            );
          })}

          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? "처리 중..." : submitLabel}
          </button>
        </Form>

        <p className="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
          {footer.text}{" "}
          <Link to={footer.to} className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
            {footer.linkLabel}
          </Link>
        </p>
      </div>
    </main>
  );
}
