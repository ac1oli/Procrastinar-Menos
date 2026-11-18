import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClockIcon, MoonIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { ApiError } from '@/api/client'
import { routine } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { ActivityCategory, RoutineBlock, RoutineBlockInput } from '@/api/types'
import { ConfirmDialog, useConfirm } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardContent, EmptyState, Skeleton } from '@/components/ui/display'
import { Checkbox, Field, Input, Label, NativeSelect, Switch, Textarea } from '@/components/ui/form-controls'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/overlays'
import { categoryLabel, weekdays } from '@/lib/labels'

export function RoutinePage() {
  const list = useQuery({ queryKey: keys.routine, queryFn: routine.list })
  const [editing, setEditing] = useState<RoutineBlock | 'new' | null>(null)
  const removing = useConfirm<RoutineBlock>()
  const queryClient = useQueryClient()

  const remove = useMutation({
    mutationFn: (b: RoutineBlock) => routine.remove(b.id),
    onSuccess: () => {
      removing.close()
      toast.success('Bloco excluído.')
      queryClient.invalidateQueries({ queryKey: keys.routine })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const blocks = [...(list.data ?? [])].sort((a, b) => a.start_time.localeCompare(b.start_time))

  return (
    <>
      <PageHeader
        title="Rotina"
        description="Blocos recorrentes que você planeja. É planejamento, não cobrança: ajuste sempre que a vida mudar. Funciona mesmo sem a IA."
        actions={
          <Button onClick={() => setEditing('new')}>
            <PlusIcon /> Novo bloco
          </Button>
        }
      />
      {list.isPending ? (
        <Skeleton className="h-40" />
      ) : list.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(list.error)}
        </p>
      ) : blocks.length === 0 ? (
        <EmptyState icon={<CalendarClockIcon />} title="Nenhum bloco na rotina">
          Comece com algo pequeno, como 30 minutos de estudo em dois dias da semana.
        </EmptyState>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {blocks.map((b) => (
            <li key={b.id}>
              <Card className={b.is_active ? 'gap-3' : 'gap-3 opacity-60'}>
                <CardContent className="grid gap-2">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-medium">{b.title}</p>
                      <p className="text-sm text-muted-foreground">
                        {b.start_time}–{b.end_time}
                        {b.crosses_midnight && (
                          <span className="ml-1 inline-flex items-center gap-1">
                            <MoonIcon className="size-3" /> atravessa a meia-noite
                          </span>
                        )}
                      </p>
                    </div>
                    <Badge variant="secondary">{categoryLabel[b.category]}</Badge>
                  </div>
                  <div className="flex flex-wrap gap-1" aria-label="Dias da semana">
                    {weekdays.map((d) => (
                      <span
                        key={d.iso}
                        title={d.long}
                        className={
                          b.days_of_week.includes(d.iso)
                            ? 'rounded-md bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary'
                            : 'rounded-md px-1.5 py-0.5 text-xs text-muted-foreground/60'
                        }
                      >
                        {d.short}
                      </span>
                    ))}
                    {!b.is_active && <Badge variant="outline">Pausado</Badge>}
                  </div>
                  {b.notes && <p className="text-xs text-muted-foreground">{b.notes}</p>}
                  <div className="flex gap-1">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(b)}>
                      <PencilIcon /> Editar
                    </Button>
                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => removing.open(b)}>
                      <Trash2Icon /> Excluir
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}

      {editing && <RoutineForm block={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={removing.isOpen}
        onOpenChange={(o) => !o && removing.close()}
        title="Excluir este bloco?"
        description={`“${removing.target?.title}” sairá da sua rotina.`}
        confirmLabel="Excluir"
        pending={remove.isPending}
        onConfirm={() => removing.target && remove.mutate(removing.target)}
      />
    </>
  )
}

function RoutineForm({ block, onClose }: { block?: RoutineBlock; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<RoutineBlockInput>({
    title: block?.title ?? '',
    category: block?.category ?? 'study',
    days_of_week: block?.days_of_week ?? [],
    start_time: block?.start_time ?? '19:00',
    end_time: block?.end_time ?? '20:00',
    is_active: block?.is_active ?? true,
    notes: block?.notes ?? '',
  })
  const save = useMutation({
    mutationFn: () => (block ? routine.update(block.id, form) : routine.create(form)),
    onSuccess: () => {
      toast.success(block ? 'Bloco atualizado.' : 'Bloco criado.')
      queryClient.invalidateQueries({ queryKey: keys.routine })
      onClose()
    },
  })
  const error = save.error instanceof ApiError ? save.error : undefined
  const toggleDay = (iso: number, on: boolean) =>
    setForm((f) => ({ ...f, days_of_week: on ? [...f.days_of_week, iso].sort() : f.days_of_week.filter((d) => d !== iso) }))

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{block ? 'Editar bloco' : 'Novo bloco da rotina'}</DialogTitle>
          <DialogDescription>Se o fim for antes do início, o bloco atravessa a meia-noite (ex.: sono).</DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <Field label="Título" htmlFor="block-title" error={error?.field('title')}>
            <Input id="block-title" maxLength={120} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          </Field>
          <Field label="Categoria" htmlFor="block-category" error={error?.field('category')}>
            <NativeSelect id="block-category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value as ActivityCategory })}>
              {Object.entries(categoryLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">Dias</legend>
            <div className="flex flex-wrap gap-3">
              {weekdays.map((d) => (
                <div key={d.iso} className="flex items-center gap-1.5">
                  <Checkbox id={`day-${d.iso}`} checked={form.days_of_week.includes(d.iso)} onCheckedChange={(v) => toggleDay(d.iso, v === true)} />
                  <Label htmlFor={`day-${d.iso}`} className="font-normal" aria-label={d.long}>
                    {d.short}
                  </Label>
                </div>
              ))}
            </div>
            {error?.field('days_of_week') && (
              <p className="text-xs text-destructive" role="alert">
                {error.field('days_of_week')}
              </p>
            )}
          </fieldset>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Início" htmlFor="block-start" error={error?.field('start_time')}>
              <Input id="block-start" type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
            </Field>
            <Field label="Fim" htmlFor="block-end" error={error?.field('end_time')}>
              <Input id="block-end" type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
            </Field>
          </div>
          <Field label="Observações (não são enviadas ao NEXO)" htmlFor="block-notes" error={error?.field('notes')}>
            <Textarea id="block-notes" maxLength={2000} value={form.notes ?? ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </Field>
          <div className="flex items-center gap-2">
            <Switch id="block-active" checked={form.is_active} onCheckedChange={(v) => setForm({ ...form, is_active: v })} />
            <Label htmlFor="block-active">Ativo</Label>
          </div>
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
