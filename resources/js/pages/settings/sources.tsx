import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import SourceSettingsController from '@/actions/App/Http/Controllers/Settings/SourceSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/sources';

type Source = {
    value: string;
    label: string;
    legal: boolean;
    enabled: boolean;
    globallyEnabled: boolean;
};

type SourcesProps = {
    sources: Source[];
};

const descriptions: Record<string, string> = {
    wikidata:
        'Public domain films imported from Wikidata. Every entry is legally distributable.',
    whitelist: 'Carefully selected movies comming from archive.org',
};

export default function Sources({ sources }: SourcesProps) {
    const [checked, setChecked] = useState<Record<string, boolean>>(
        Object.fromEntries(
            sources.map((source) => [source.value, source.enabled]),
        ),
    );

    return (
        <>
            <Head title="Source settings" />

            <h1 className="sr-only">Source settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Sources"
                    description="Choose which catalogs the search engine queries for you"
                />

                <Form
                    {...SourceSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-4">
                                {sources.map((source) => (
                                    <div
                                        key={source.value}
                                        className="flex items-start gap-3 rounded-lg border p-4"
                                    >
                                        <input
                                            type="hidden"
                                            name={`sources[${source.value}]`}
                                            value={
                                                checked[source.value]
                                                    ? '1'
                                                    : '0'
                                            }
                                        />
                                        <Checkbox
                                            id={`sources-${source.value}`}
                                            checked={checked[source.value]}
                                            disabled={!source.globallyEnabled}
                                            onCheckedChange={(value) =>
                                                setChecked((current) => ({
                                                    ...current,
                                                    [source.value]:
                                                        Boolean(value),
                                                }))
                                            }
                                        />

                                        <div className="grid gap-1.5 leading-tight">
                                            <Label
                                                htmlFor={`sources-${source.value}`}
                                                className="flex items-center gap-2"
                                            >
                                                {source.label}
                                                {source.legal ? (
                                                    <Badge variant="secondary">
                                                        Legal
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="destructive">
                                                        Not legal
                                                    </Badge>
                                                )}
                                            </Label>

                                            <p className="text-sm text-muted-foreground">
                                                {descriptions[source.value] ??
                                                    (source.globallyEnabled
                                                        ? 'Catalog queried by the search engine.'
                                                        : 'This source is disabled by the server administrator.')}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <InputError message={errors.sources} />

                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="update-sources-button"
                            >
                                {processing ? 'Saving…' : 'Save sources'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Sources.layout = {
    breadcrumbs: [
        {
            title: 'Source settings',
            href: edit(),
        },
    ],
};
