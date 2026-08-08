# Google Sheets API Writer

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/keboola/google-sheets-writer/blob/master/LICENSE.md)

This component writes data to Google Drive files and spreadsheets.

## Example Configuration

```json
{}
```

## OAuth Registration

This writer uses the [Keboola OAuth Bundle](https://github.com/keboola/oauth-v2-bundle) to store OAuth credentials.

1. Create an application in the Google Developer Console:

- Enable the following APIs:
    - `Google Drive API`
    - ` Google Sheets API`
- Got to the **Credentials** section and create a new credential of type `OAuth Client ID`.
- Use `https://SYRUP_INSTANCE.keboola.com/oauth-v2/authorize/keboola.ex-google-drive/callback` as a redirect URI.

2. Register the application in Keboola Oauth.

- Follow the instructions in the [OAuth v2 API documentation](http://docs.oauthv2.apiary.io/#reference/manage/addlist-supported-api/add-new-component).


```
{ 
    "component_id": "keboola.wr-google-sheets",
    "friendly_name": "Google Sheets Writer",
    "app_key": "XXX.apps.googleusercontent.com",
    "app_secret": "",
    "auth_url": "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&redirect_uri=%%redirect_uri%%&client_id=%%client_id%%&access_type=offline&prompt=consent&scope=https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/spreadsheets.readonly",
    "token_url": "https://www.googleapis.com/oauth2/v4/token",
    "oauth_version": "2.0"
}
```

## Development

This application is developed locally using Test-Driven Development (TDD).

1. Clone the repository: `git clone git@github.com:keboola/google-sheets-writer.git`
2. Navigate to the project directory: `cd google-sheets-writer`
3. Install dependencies: `docker-compose run --rm dev composer install`
4. Create a `.env` file from the template `.env.dist`. 
5. Obtaing working OAuth credentials: 
    - Visit Google's [OAuth 2.0 Playground](https://developers.google.com/oauthplayground). 
    - Open the configuration settings (gear icon in the top right corner).
    - Enable **Use your own OAuth credentials** and enter your OAuth Client ID and Secret.
    - Complete the authorization flor to generate **Access** and **Refresh** tokens.
    - Copy and paste these tokens into the `.env` file.    
6. Run the test suite: `docker-compose run --rm dev composer tests`

## License

The project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for details.
