import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Clock3,
    FileText,
    Info,
    PlayCircle,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type LogEntry = {
    time: string;
    channel: string;
    event: string;
    payload: Record<string, unknown>;
    raw: string;
};

type TaskPlanStep = {
    title?: string;
    status?: string;
    attempt_count?: number;
    review?: {
        result?: string;
        summary?: string;
    };
};

type WorkflowRun = {
    conversation_id: string;
    user_id: number | null;
    mode: string | null;
    status: 'running' | 'success' | 'failed';
    started_at: string;
    ended_at: string | null;
    duration_ms: number | null;
    model: string | null;
    intent: string | null;
    fallback_used: boolean | null;
    stream: boolean | null;
    events: LogEntry[];
    final_response?: string | null;
    final_response_preview?: string | null;
    failure_reason?: string | null;
    task_run?: {
        id: number;
        status: string;
        goal: string;
        current_step_index: number;
        plan: TaskPlanStep[];
    } | null;
};

type Props = {
    runs: WorkflowRun[];
    entries: LogEntry[];
    logPath: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AI Logs',
        href: '/ai-assistant/logs',
    },
];

function statusColor(status: WorkflowRun['status']): string {
    if (status === 'success') {
        return 'text-emerald-600';
    }

    if (status === 'failed') {
        return 'text-red-600';
    }

    return 'text-amber-600';
}

function statusIcon(status: WorkflowRun['status']) {
    if (status === 'success') {
        return <CheckCircle2 className="size-4 text-emerald-600" />;
    }

    if (status === 'failed') {
        return <XCircle className="size-4 text-red-600" />;
    }

    return <Clock3 className="size-4 text-amber-600" />;
}

function eventLabel(event: string): string {
    if (event.endsWith('.request')) {
        return 'Request started';
    }

    if (event.endsWith('.success')) {
        return 'Completed successfully';
    }

    if (event.endsWith('.failed')) {
        return 'Failed with error';
    }

    return event;
}

function flowStages(run: WorkflowRun): string[] {
    const stages = ['request'];
    const hasSuccess = run.events.some((event) => event.event.endsWith('.success'));
    const hasFailure = run.events.some((event) => event.event.endsWith('.failed'));
    const isDeep = run.mode === 'deep' || run.intent === 'deep-autonomous';

    if (isDeep) {
        stages.push('planning');
    }

    if (run.task_run && run.task_run.plan.length > 0) {
        stages.push('autonomous_steps');
        stages.push('review');
    } else {
        stages.push('execution');
    }

    if (hasSuccess) {
        stages.push('success');
    } else if (hasFailure) {
        stages.push('failed');
    } else {
        stages.push('running');
    }

    return stages;
}

function stagePill(stage: string): string {
    return {
        request: 'Request',
        planning: 'Planning',
        execution: 'Execution',
        autonomous_steps: 'Autonomous Steps',
        review: 'Review',
        success: 'Success',
        failed: 'Failed',
        running: 'Running',
    }[stage] ?? stage;
}

export default function AiAssistantLogsPage({ runs, entries, logPath }: Props) {
    const [selectedRun, setSelectedRun] = useState<WorkflowRun | null>(null);

    const totalSuccess = useMemo(
        () => runs.filter((run) => run.status === 'success').length,
        [runs],
    );
    const totalFailed = useMemo(
        () => runs.filter((run) => run.status === 'failed').length,
        [runs],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI Assistant Logs" />

            <div className="space-y-6 p-4 md:p-6">
                <section className="rounded-xl border bg-card p-4">
                    <h1 className="text-xl font-semibold">AI Assistant Workflow Logs</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Clear run cards with outcome, final response/error reason, and
                        drill-down drawer from `{logPath}`.
                    </p>
                    <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <Badge variant="secondary">Runs: {runs.length}</Badge>
                        <Badge variant="secondary" className="text-emerald-700">
                            Success: {totalSuccess}
                        </Badge>
                        <Badge variant="secondary" className="text-red-700">
                            Failed: {totalFailed}
                        </Badge>
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Run Cards</h2>
                    {runs.length === 0 ? (
                        <div className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                            No AI assistant workflow logs found.
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-5">
                            {runs.map((run, index) => (
                                <Card
                                    key={`${run.conversation_id}-${run.started_at}-${index}`}
                                    className="aspect-square border-border/70"
                                >
                                    <CardHeader className="space-y-2 pb-2">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                {statusIcon(run.status)}
                                                <CardTitle className="text-xs">
                                                    Conversation #{run.conversation_id}
                                                </CardTitle>
                                                <span
                                                    className={`text-[10px] font-semibold uppercase tracking-wide ${statusColor(run.status)}`}
                                                >
                                                    {run.status}
                                                </span>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setSelectedRun(run)}
                                                className="h-7 px-2 text-[11px]"
                                            >
                                                Open details
                                            </Button>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-2 overflow-auto text-xs">
                                        <div className="flex flex-wrap gap-1.5 text-[10px]">
                                            {run.mode && <Badge variant="secondary">mode={run.mode}</Badge>}
                                            {run.model && <Badge variant="secondary">model={run.model}</Badge>}
                                            {run.intent && <Badge variant="secondary">intent={run.intent}</Badge>}
                                            {typeof run.duration_ms === 'number' && (
                                                <Badge variant="secondary">{run.duration_ms}ms</Badge>
                                            )}
                                        </div>

                                        <div className="text-[11px] text-muted-foreground">
                                            <p>Started: {run.started_at}</p>
                                            <p>Ended: {run.ended_at ?? 'in-progress'}</p>
                                        </div>

                                        <div className="rounded-md border bg-muted/40 p-2 text-[11px]">
                                            <p className="mb-1 flex items-center gap-1.5 text-[10px] font-semibold uppercase text-muted-foreground">
                                                <FileText className="size-3.5" />
                                                {run.status === 'failed'
                                                    ? 'Failure Reason'
                                                    : 'Final Response Preview'}
                                            </p>
                                            <p className="max-h-20 overflow-auto whitespace-pre-wrap">
                                                {run.status === 'failed'
                                                    ? run.failure_reason ?? 'No explicit reason found in logs.'
                                                    : run.final_response_preview ??
                                                      'No assistant response captured for this run.'}
                                            </p>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-1.5 text-[10px]">
                                            {flowStages(run).map((stage, stageIndex) => (
                                                <div
                                                    key={`${stage}-${stageIndex}`}
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <span className="rounded-md border bg-background px-1.5 py-0.5">
                                                        {stagePill(stage)}
                                                    </span>
                                                    {stageIndex < flowStages(run).length - 1 && (
                                                        <ArrowRight className="size-3 text-muted-foreground" />
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-xl border bg-card p-4">
                    <h2 className="text-base font-semibold">
                        Raw Event Inspector ({entries.length})
                    </h2>
                    <div className="mt-3 max-h-[320px] space-y-2 overflow-auto">
                        {entries.map((entry, index) => (
                            <details
                                key={`${entry.time}-${entry.event}-${index}`}
                                className="rounded border bg-background p-2"
                            >
                                <summary className="cursor-pointer text-sm font-medium">
                                    {entry.time} · {entry.event}
                                </summary>
                                <pre className="mt-2 overflow-x-auto rounded bg-muted p-2 text-xs">
                                    {JSON.stringify(entry.payload, null, 2)}
                                </pre>
                            </details>
                        ))}
                    </div>
                </section>
            </div>

            <Sheet open={selectedRun !== null} onOpenChange={(open) => !open && setSelectedRun(null)}>
                <SheetContent side="right" className="w-full sm:max-w-3xl">
                    {selectedRun && (
                        <>
                            <SheetHeader>
                                <SheetTitle className="flex items-center gap-2">
                                    {statusIcon(selectedRun.status)}
                                    Run details · Conversation #{selectedRun.conversation_id}
                                </SheetTitle>
                                <SheetDescription>
                                    Full workflow, final output/reason, and timeline.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="space-y-5 overflow-y-auto px-4 pb-6 text-sm">
                                <section className="rounded-lg border p-3">
                                    <p className="font-medium">Flowchart</p>
                                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        {flowStages(selectedRun).map((stage, index) => (
                                            <div key={`${stage}-${index}`} className="flex items-center gap-2">
                                                <span className="rounded-md border bg-muted px-2 py-1">
                                                    {stagePill(stage)}
                                                </span>
                                                {index < flowStages(selectedRun).length - 1 && (
                                                    <ArrowRight className="size-3 text-muted-foreground" />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </section>

                                <section className="rounded-lg border p-3">
                                    <p className="mb-2 font-medium">Final Response</p>
                                    <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded bg-muted p-3 text-xs">
                                        {selectedRun.final_response ??
                                            'No final assistant response captured.'}
                                    </pre>
                                </section>

                                <section className="rounded-lg border p-3">
                                    <p className="mb-2 flex items-center gap-2 font-medium">
                                        <Info className="size-4 text-red-500" />
                                        Why it failed (if any)
                                    </p>
                                    <p className="whitespace-pre-wrap text-xs text-muted-foreground">
                                        {selectedRun.failure_reason ?? 'Run did not fail.'}
                                    </p>
                                </section>

                                {selectedRun.task_run && (
                                    <section className="rounded-lg border p-3">
                                        <p className="font-medium">
                                            Autonomous Step Workflow (Task Run #{selectedRun.task_run.id})
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Status: {selectedRun.task_run.status}
                                        </p>
                                        <div className="mt-3 space-y-2">
                                            {selectedRun.task_run.plan.map((step, index) => (
                                                <div key={`${step.title ?? 'step'}-${index}`} className="rounded border p-2">
                                                    <p className="text-xs font-semibold">
                                                        Step {index + 1}: {step.title ?? 'Untitled step'}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        status={step.status ?? 'pending'}
                                                        {typeof step.attempt_count === 'number'
                                                            ? ` · attempts=${step.attempt_count}`
                                                            : ''}
                                                        {step.review?.result
                                                            ? ` · review=${step.review.result}`
                                                            : ''}
                                                    </p>
                                                    {step.review?.summary && (
                                                        <p className="mt-1 whitespace-pre-wrap text-xs">
                                                            {step.review.summary}
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                )}

                                <section className="rounded-lg border p-3">
                                    <p className="font-medium">Event Timeline</p>
                                    <ol className="mt-3 space-y-2 border-l pl-4">
                                        {selectedRun.events.map((event, index) => (
                                            <li key={`${event.time}-${event.event}-${index}`} className="relative rounded border bg-background p-2">
                                                <span className="absolute -left-[21px] top-3 size-2 rounded-full bg-primary" />
                                                <p className="text-xs font-medium">
                                                    {eventLabel(event.event)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">{event.time}</p>
                                                <pre className="mt-2 overflow-auto whitespace-pre-wrap rounded bg-muted p-2 text-[11px]">
                                                    {JSON.stringify(event.payload, null, 2)}
                                                </pre>
                                            </li>
                                        ))}
                                    </ol>
                                </section>
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}
