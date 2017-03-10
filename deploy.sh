#!/bin/bash
docker login -u="$QUAY_USERNAME" -p="$QUAY_PASSWORD" quay.io
docker tag keboola/google-drive-sheets quay.io/keboola/google-sheets-writer:$TRAVIS_TAG
docker images
docker push quay.io/keboola/google-sheets-writer:$TRAVIS_TAG
