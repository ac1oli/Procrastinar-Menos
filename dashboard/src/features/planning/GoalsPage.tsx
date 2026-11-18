import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PencilIcon, PlusIcon, TargetIcon, Trash2Icon } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { ApiError } from '@/api/client'
import { goals } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { Goal, GoalInput, GoalStatus, GoalType } from '@/api/types'
import { ConfirmDialog, useConfirm } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardContent, EmptyState, Skeleton } from '@/components/ui/display'
import { Field, Input, NativeSelect, Textarea } from '@/components/ui/form-controls'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/overlays'
import { formatDate, goalStatusLabel, goalTypeLabel } from '@/lib/labels'

const statusOrder: GoalStatus[] = ['active', 'paused', 'completed', 'abandoned']

export function GoalsPage() {
  const list = useQuery({ queryKey: keys.goals, queryFn: goals.list })
  const [editing, setEditing] = useState<Goal | 'new' | null>(null)
  const removing = useConfirm<Goal>()
  const queryClient = useQueryClient()

  const remove = useMutation({
    mutationFn: (g: Goal) => goals.remove(g.id),
    onSuccess: () => {
      removing.close()
      toast.success('Objetivo excluído.')
      queryClient.invalidateQueries({ queryKey: keys.goals })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <>
      <PageHeader
        title="Objetivos"
        description="Objetivos pessoais, profissionais e de aprendizagem. Com autorização, o NEXO considera até 3 ativos, sem as descrições."
        actions={
          <Button onClick={() => setEditing('new')}>
            <PlusIcon /> Novo objetivo
          </Button>
        }
      />
      {list.isPending ? (
        <Skeleton className="h-40" />
      ) : list.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(list.error)}
        </p>
      ) : list.data.length === 0 ? (
        <EmptyState icon={<TargetIcon />} title="Nenhum objetivo ainda">
          Um bom objetivo é concreto e possível, como “Terminar o curso de SQL até junho”.
        </EmptyState>
      ) : (
        <div className="grid gap-6">
          {statusOrder.map((status) => {
            const items = list.data.filter((g) => g.status === status)
            if (!items.length) return null
            return (
              <section key={status} className="grid gap-3">
                <h2 className="text-sm font-medium text-muted-foreground">{goalStatusLabel[status]}</h2>
                <ul className="grid gap-3 md:grid-cols-2">
                  {items.map((g) => (
                    <li key={g.id}>
                      <Card className="gap-3">
                        <CardContent className="grid gap-2">
                          <div className="flex items-start justify-between gap-2">
                            <p className="font-medium">{g.title}</p>
                            <Badge variant="secondary">{goalTypeLabel[g.type]}</Badge>
                          </div>
                          {g.description && <p className="text-sm text-muted-foreground">{g.description}</p>}
                          <p className="text-xs text-muted-foreground">{g.target_date ? `Prazo: ${formatDate(g.target_date)}` : 'Sem prazo'}</p>
                          <div className="flex gap-1">
                            <Button size="sm" variant="ghost" onClick={() => setEditing(g)}>
                              <PencilIcon /> Editar
                            </Button>
                            <Button size="sm" variant="ghost" className="text-destructive" onClick={() => removing.open(g)}>
                              <Trash2Icon /> Excluir
                            </Button>
                          </div>
                        </CardContent>
                      </Card>
                    </li>
                  ))}
                </ul>
              </section>
            )
          })}
        </div>
      )}
      {editing && <GoalForm goal={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={removing.isOpen}
        onOpenChange={(o) => !o && removing.close()}
        title="Excluir este objetivo?"
        description={`“${removing.target?.title}” será excluído.`}
        confirmLabel="Excluir"
        pending={remove.isPending}
        onConfirm={() => removing.target && remove.mutate(removing.target)}
      />
    </>
  )
}

function GoalForm({ goal, onClose }: { goal?: Goal; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<GoalInput & { status: GoalStatus }>({
    title: goal?.title ?? '',
    type: goal?.type ?? 'learning',
    status: goal?.status ?? 'active',
    description: goal?.description ?? '',
    target_date: goal?.target_date ?? '',
  })
  const save = useMutation({
    mutationFn: () => {
      const input = { ...form, description: form.description || null, target_date: form.target_date || null }
      return goal ? goals.update(goal.id, input) : goals.create(input)
    },
    onSuccess: () => {
      toast.success(goal ? 'Objetivo atualizado.' : 'Objetivo criado.')
      queryClient.invalidateQueries({ queryKey: keys.goals })
      onClose()
    },
  })
  const error = save.error instanceof ApiError ? save.error : undefined

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{goal ? 'Editar objetivo' : 'Novo objetivo'}</DialogTitle>
          <DialogDescription>Descrições ficam só com você: não são enviadas ao NEXO.</DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <Field label="Título" htmlFor="goal-title" error={error?.field('title')}>
            <Input id="goal-title" maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Tipo" htmlFor="goal-type" error={error?.field('type')}>
              <NativeSelect id="goal-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as GoalType })}>
                {Object.entries(goalTypeLabel).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label="Estado" htmlFor="goal-status" error={error?.field('status')}>
              <NativeSelect id="goal-status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as GoalStatus })}>
                {statusOrder.map((s) => (
                  <option key={s} value={s}>
                    {goalStatusLabel[s]}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <Field label="Prazo (opcional)" htmlFor="goal-date" error={error?.field('target_date')}>
            <Input id="goal-date" type="date" value={form.target_date ?? ''} onChange={(e) => setForm({ ...form, target_date: e.target.value })} />
          </Field>
          <Field label="Descrição (opcional)" htmlFor="goal-description" error={error?.field('description')}>
            <Textarea id="goal-description" maxLength={5000} value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </Field>
          {save.isError && Object.keys(error?.errors ?? {}).length === 0 && (
            <p role="alert" className="text-sm text-destructive">
              {errorMessage(save.error)}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? 'Salvando…' : 'Salvar'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
